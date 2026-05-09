<?php

declare(strict_types=1);

namespace Kraite\Core\Observers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kraite\Core\Exceptions\NonNotifiableException;
use Kraite\Core\Jobs\Atomic\Position\SyncPositionQuantityFromExchangeJob;
use Kraite\Core\Jobs\Lifecycles\Order\PrepareOrderCorrectionJob;
use Kraite\Core\Jobs\Lifecycles\Position\ApplyWapJob;
use Kraite\Core\Jobs\Lifecycles\Position\ClosePositionJob;
use Kraite\Core\Jobs\Lifecycles\Position\PreparePositionReplacementJob;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\Math;
use Kraite\Core\Support\Proxies\JobProxy;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Dispatched;
use StepDispatcher\States\Pending;
use StepDispatcher\States\Running;
use StepDispatcher\Support\Steps;

/**
 * Two detection routes handle a user-side modification of our orders on
 * the exchange. Which route fires depends on whether the exchange
 * supports in-place modify for the order's type.
 *
 * 1) Drift route (supports in-place modify — Binance LIMIT, etc.):
 *    - The order keeps its exchange_order_id.
 *    - apiSync pulls the new price/quantity into the DB.
 *    - `checkForOrderModification` compares price/quantity against
 *      reference_* and — if diverged — dispatches
 *      PrepareOrderCorrectionJob, whose LIMIT branch restores the
 *      reference values via apiModify().
 *
 * 2) Replacement route (no in-place modify — Binance algo orders: the
 *    UI "Edit" action cancels the original and places a new algo order
 *    at the new trigger, leaving a ghost we never saw):
 *    - Our original order reports status=CANCELLED on the next sync.
 *    - `updated()` routes CANCELLED PROFIT/STOP through
 *      `dispatchPositionReplacement` →
 *      PreparePositionReplacementJob → VerifyPositionExistsOnExchange
 *      → SmartReplaceOrdersJob.
 *    - SmartReplaceOrdersJob first runs CancelOrphanAlgoOrdersJob
 *      (exchange-specific; Binance cancels any algo on our symbol
 *      whose algoId isn't in our known set — scrubs the ghost) and
 *      then RecreateCancelledOrderJob recreates our reference order.
 *
 * Other exchanges resolve the orphan-scrub step to a no-op via
 * JobProxy; if a new exchange is added where the algo modify flow
 * also leaves ghosts, implement a variant under
 * `Jobs/Atomic/Order/{Exchange}/CancelOrphanAlgoOrdersJob`.
 */
final class OrderObserver
{
    private const array INACTIVE_STATUSES = ['CANCELLED', 'EXPIRED'];

    private const array CLOSE_TRIGGER_TYPES = ['PROFIT-LIMIT', 'PROFIT-MARKET', 'STOP-MARKET'];

    public function creating(Order $model): bool
    {
        // Auto-generate UUIDs if missing
        $model->uuid ??= Str::uuid()->toString();
        $model->client_order_id ??= Str::uuid()->toString();

        // Flag conditional orders for exchange-specific routing
        // Each exchange has different endpoints for stop orders:
        // - Binance: Algo Order API (/fapi/v1/algoOrder)
        // - KuCoin: Stop Orders API (/api/v1/stopOrders)
        // - Bitget: Plan Order API (/api/v2/mix/order/place-plan-order)
        // - Bybit: Same endpoint with triggerPrice parameter (uses orderFilter for queries)
        if ($model->type === 'STOP-MARKET' && $model->position?->account?->apiSystem !== null) {
            $canonical = $model->position->account->apiSystem->canonical;
            if (in_array($canonical, ['binance', 'kucoin', 'bitget', 'bybit'], strict: true)) {
                $model->is_algo = true;
            }
        }

        return $this->enforceOrderLimits($model);
    }

    public function updating(Order $model): void
    {
        // TRIGGERED is the canonical truth that an algo order's trigger
        // condition fired on the exchange. Once recorded, no caller may
        // overwrite it — sync-orders' empty-openAlgoOrders fallback returns
        // CANCELLED, the close-flow's algo-cancel API returns CANCELLED on
        // an already-finished order, and any future reconciler that
        // re-stamps from a stale snapshot would erase the audit trail.
        // Pinning here is one chokepoint that protects every write path.
        if ($model->getOriginal('status') === 'TRIGGERED' && $model->status !== 'TRIGGERED') {
            $model->status = 'TRIGGERED';
        }

        // Only stamp filled_at on the *first* transition into FILLED. Without
        // the null guard every subsequent save (e.g. a later sync tick that
        // re-writes status=FILLED, or the close workflow's re-sync) would
        // overwrite the original fill timestamp with now(), losing the actual
        // moment the exchange reported the fill.
        if ($model->status === 'FILLED' && $model->filled_at === null) {
            $model->filled_at = now();
        }
    }

    /**
     * After an order is updated, check if status changed and react accordingly.
     *
     * PROFIT/STOP orders:
     * - FILLED → ClosePositionJob (orderly close)
     * - EXPIRED/CANCELLED → PreparePositionReplacementJob
     *
     * LIMIT orders (DCA):
     * - EXPIRED/CANCELLED → PreparePositionReplacementJob
     *   (queries exchange to verify position still exists before recreating)
     *
     * Price/Quantity modification detection:
     * - Active orders with price != reference_price or quantity != reference_quantity
     *   trigger PrepareOrderCorrectionJob to restore original values.
     *
     * Uses reference_status to detect changes idempotently — prevents
     * double-dispatch when multiple syncs process the same order.
     */
    public function updated(Order $model): void
    {
        $position = $model->position;

        if ($position === null) {
            return;
        }

        // Guard against dispatch if position is already closing/closed/cancelled
        if (! in_array($position->status, $position->activeStatuses(), true)) {
            return;
        }

        // First, check for price/quantity modification on active orders.
        // This runs even if status matches reference_status (modification doesn't change status)
        if (in_array($model->status, ['NEW', 'PARTIALLY_FILLED'], true)) {
            $this->checkForOrderModification($model, $position);
        }

        // Skip status-based checks if status matches reference (already handled)
        if ($model->status === $model->reference_status) {
            return;
        }

        // PROFIT/STOP orders: FILLED or TRIGGERED triggers close, CANCELLED/EXPIRED triggers replacement.
        //
        // TRIGGERED is added alongside FILLED here because Binance's algo SL
        // surfaces an SL-fire event with `algoStatus=TRIGGERED` (mapped
        // faithfully — see MapsPlaceAlgoOrder::mapAlgoStatus). The
        // exchange-side outcome is identical to a fill from the position's
        // perspective: the algo is finished and a reduce-only MARKET has
        // already been auto-placed. The close lifecycle must run.
        if (in_array($model->type, self::CLOSE_TRIGGER_TYPES, true)) {
            match ($model->status) {
                'FILLED', 'TRIGGERED' => $this->dispatchClosePosition($model, $position),
                'EXPIRED', 'CANCELLED' => $this->dispatchPositionReplacement($model, $position),
                default => null,
            };

            return;
        }

        // LIMIT orders: CANCELLED/EXPIRED triggers replacement (recreate missing DCA orders)
        // Position existence is verified by PreparePositionReplacementJob before recreating
        if ($model->type === 'LIMIT' && in_array($model->status, ['EXPIRED', 'CANCELLED'], true)) {
            $this->dispatchPositionReplacement($model, $position);

            return;
        }

        // LIMIT orders: FILLED triggers WAP recalculation
        // When a DCA order fills, recalculate profit order based on breakEvenPrice
        if ($model->type === 'LIMIT' && $model->status === 'FILLED') {
            $this->dispatchApplyWap($model, $position);
        }

        // LIMIT orders: PARTIALLY_FILLED triggers a position-quantity-only
        // refresh from the exchange. Strictly NOT routed through WAP — TP
        // price recalc is reserved for full fills. This branch keeps the
        // local positions.quantity mirror tracking the running net size
        // during the multi-chunk DCA fill window. reference_status is
        // intentionally NOT bumped to PARTIALLY_FILLED so that subsequent
        // chunk arrivals (each one a distinct fill event landing at the
        // same status) still re-fire this branch instead of being swallowed
        // by the upstream `status === reference_status` early-return.
        if ($model->type === 'LIMIT' && $model->status === 'PARTIALLY_FILLED') {
            $this->dispatchSyncPositionQuantity($position);
        }
    }

    private function dispatchClosePosition(Order $model, mixed $position): void
    {
        // Deduplicate: skip if ClosePositionJob already pending/running for this position.
        // Prevents a second close workflow when both TP and SL reach FILLED in the same
        // sync cycle — the second run would collide with the first and mark the
        // position 'failed' on the "already closing/closed" guard.
        //
        // Cross-process atomicity: the SELECT-then-INSERT below races
        // when two horizon workers process simultaneous WS pushes for
        // the same position (TP-FILLED + SL-FILLED in the same cycle).
        // `lockForUpdate` on the parent Position row inside a DB
        // transaction serialises every dispatch attempt for that
        // position — first worker holds the lock, every other worker
        // blocks until commit, then sees the freshly-inserted Step
        // and skips. Atomic across every worker, every server, every
        // connection. Lock is released on commit/rollback (worker
        // crash safe).
        //
        // Outer Steps::usingPrefix scopes BOTH the dedupe SELECT and
        // the close-step INSERT to `trading_steps`. Without this, the
        // dedupe queries the default table and false-negatives every
        // time (no rows in `steps` for this position) — so duplicate
        // close workflows would race past the guard.
        Steps::usingPrefix('trading', function () use ($model, $position): void {
            DB::transaction(function () use ($model, $position): void {
                Position::query()->whereKey($position->id)->lockForUpdate()->first();

                $alreadyPending = Step::query()
                    ->where('class', ClosePositionJob::class)
                    ->whereRaw("JSON_EXTRACT(arguments, '$.positionId') = ?", [$position->id])
                    ->whereIn('state', [Pending::class, Dispatched::class, Running::class])
                    ->exists();

                if ($alreadyPending) {
                    return;
                }

                // Mirror the live status onto reference_status so that the
                // upstream `$model->status === $model->reference_status`
                // early-return short-circuits any second observer fire on
                // this same row. Hardcoding 'FILLED' here was safe while
                // STOP-MARKET close was triggered exclusively on FILLED,
                // but TRIGGERED is now a first-class close trigger too —
                // without using the live status, the row's reference
                // would lag behind on a TRIGGERED close and the observer
                // would re-enter dispatchClosePosition infinitely
                // (status='TRIGGERED' vs reference='FILLED'), blowing
                // the stack and crashing the worker mid-event.
                $model->updateSaving(['reference_status' => $model->status]);

                // Set status to 'closing' immediately so SyncPositionOrdersJob
                // doesn't override it to 'active' before ClosePositionJob runs
                $position->updateToClosing();

                Step::create([
                    'class' => ClosePositionJob::class,
                    'queue' => 'positions',
                    'priority' => 'high',
                    'arguments' => [
                        'positionId' => $position->id,
                        'message' => "{$model->type} order #{$model->id} filled — closing position",
                    ],
                ]);
            });
        });
    }

    private function dispatchPositionReplacement(Order $model, mixed $position): void
    {
        // NOTE: Do NOT update reference_status here.
        // SmartReplaceOrdersJob needs to find orders where reference_status != status
        // to know which orders need recreation. RecreateCancelledOrderJob::complete()
        // updates reference_status after successful recreation.

        // Deduplicate: skip if PreparePositionReplacementJob already pending for this position.
        // Prevents multiple dispatches when several orders are cancelled at once.
        //
        // Cross-process atomicity: the SELECT-then-INSERT below races
        // when N horizon workers process simultaneous WS pushes for
        // the same position. ETC #211 incident on 2026-05-03 — four
        // LIMITs flipped CANCELLED in the same second from a
        // symbol-wide DELETE response, four workers fired the
        // observer concurrently, two won the race past the dedupe,
        // and two PreparePositionReplacementJob steps ran in
        // parallel — duplicating LIMIT rungs and killing the TP.
        //
        // `lockForUpdate` on the parent Position row inside a DB
        // transaction serialises every dispatch attempt for that
        // position — first worker holds the lock, every other worker
        // blocks until commit, then sees the freshly-inserted Step
        // and skips. Atomic across every worker, every server, every
        // connection. No Redis, no schema change, no cache.
        Steps::usingPrefix('trading', function () use ($model, $position): void {
            DB::transaction(function () use ($model, $position): void {
                Position::query()->whereKey($position->id)->lockForUpdate()->first();

                $alreadyPending = Step::query()
                    ->where('class', PreparePositionReplacementJob::class)
                    ->whereRaw("JSON_EXTRACT(arguments, '$.positionId') = ?", [$position->id])
                    ->whereIn('state', [Pending::class, Dispatched::class, Running::class])
                    ->exists();

                if ($alreadyPending) {
                    return;
                }

                $action = $model->status === 'EXPIRED' ? 'expired' : 'cancelled';

                Step::create([
                    'class' => PreparePositionReplacementJob::class,
                    'queue' => 'positions',
                    'priority' => 'high',
                    'arguments' => [
                        'positionId' => $position->id,
                        'triggerStatus' => $model->status,
                        'message' => "{$model->type} order #{$model->id} {$action} — preparing replacement",
                    ],
                ]);
            });
        });
    }

    /**
     * Dispatch ApplyWapJob when a LIMIT (DCA) order fills.
     *
     * WAP (Weighted Average Price) recalculates the profit order price
     * based on the exchange's breakEvenPrice after a DCA order fills.
     */
    private function dispatchApplyWap(Order $model, mixed $position): void
    {
        // If profit order is already FILLED, skip WAP - close workflow will handle it
        $profitOrder = $position->profitOrder();
        if ($profitOrder && $profitOrder->status === 'FILLED') {
            return;
        }

        // Deduplicate: skip if ApplyWapJob already pending for this position.
        // Prevents multiple dispatches when several LIMIT orders fill at once.
        //
        // IMPORTANT: the reference_status bump is AFTER this check, not before.
        // reference_status means "observer has acknowledged this state". When
        // dedup skips the dispatch, we have NOT acknowledged the fill — the
        // WAP currently running was for a prior fill. Leaving ref_status
        // un-bumped lets the next sync cycle re-detect this fill (now that
        // the prior WAP has cleared dedup) and dispatch a fresh WAP for it.
        // Without this ordering, a LIMIT that fills during another LIMIT's
        // WAP would have its qty effectively ignored in TP adjustment.
        //
        // Cross-process atomicity: same race class as the close /
        // replacement sites. Multiple LIMITs filling simultaneously
        // → multiple WS pushes → multiple parallel observer fires.
        // `lockForUpdate` on the parent Position row inside a DB
        // transaction serialises every WAP dispatch attempt for
        // that position; the reference_status bump and the Step
        // INSERT happen atomically with the dedupe check.
        Steps::usingPrefix('trading', function () use ($model, $position): void {
            DB::transaction(function () use ($model, $position): void {
                Position::query()->whereKey($position->id)->lockForUpdate()->first();

                $alreadyPending = Step::query()
                    ->where('class', ApplyWapJob::class)
                    ->whereRaw("JSON_EXTRACT(arguments, '$.positionId') = ?", [$position->id])
                    ->whereIn('state', [Pending::class, Dispatched::class, Running::class])
                    ->exists();

                if ($alreadyPending) {
                    return;
                }

                $model->updateSaving(['reference_status' => 'FILLED']);

                Step::create([
                    'class' => ApplyWapJob::class,
                    'queue' => 'positions',
                    'priority' => 'high',
                    'arguments' => [
                        'positionId' => $position->id,
                        'message' => "LIMIT order #{$model->id} filled — applying WAP",
                    ],
                ]);
            });
        });
    }

    /**
     * Dispatch SyncPositionQuantityFromExchangeJob when a LIMIT order
     * lands in PARTIALLY_FILLED. The job pulls the live exchange
     * position size and rewrites positions.quantity. WAP is not invoked
     * — partial fills must not retrigger TP price recalculation.
     *
     * Skip when:
     *   - position is not in an active state (close / cancel workflow
     *     owns the row)
     *
     * Cross-process atomicity: same race shape as the WAP / replacement
     * sites — multiple chunked partial fills can arrive concurrently.
     * The position-row `lockForUpdate` inside a DB transaction
     * serialises every dispatch attempt; the dedupe SELECT and the
     * Step INSERT happen atomically.
     *
     * Reference-status is intentionally NOT bumped here. Each partial
     * fill chunk is a distinct event arriving at the same status — if
     * we bumped reference_status to PARTIALLY_FILLED, the upstream
     * `status === reference_status` early-return in updated() would
     * silently swallow chunks 2..N. Step-level dedupe (Pending /
     * Dispatched / Running) gives us the safety we need without that
     * invariant collision.
     */
    private function dispatchSyncPositionQuantity(mixed $position): void
    {
        if (! in_array($position->status, $position->activeStatuses(), true)) {
            return;
        }

        Steps::usingPrefix('trading', function () use ($position): void {
            DB::transaction(function () use ($position): void {
                Position::query()->whereKey($position->id)->lockForUpdate()->first();

                $alreadyPending = Step::query()
                    ->where('class', SyncPositionQuantityFromExchangeJob::class)
                    ->whereRaw("JSON_EXTRACT(arguments, '$.positionId') = ?", [$position->id])
                    ->whereIn('state', [Pending::class, Dispatched::class, Running::class])
                    ->exists();

                if ($alreadyPending) {
                    return;
                }

                Step::create([
                    'class' => SyncPositionQuantityFromExchangeJob::class,
                    'queue' => 'positions',
                    'arguments' => [
                        'positionId' => $position->id,
                    ],
                ]);
            });
        });
    }

    /**
     * Detect if an active order was modified on the exchange.
     *
     * A modification is detected when:
     * - Order is active (NEW or PARTIALLY_FILLED)
     * - Has reference values set (reference_price and/or reference_quantity)
     * - Current price/quantity differs from reference values
     *
     * When detected, dispatches PrepareOrderCorrectionJob to restore original values.
     *
     * IMPORTANT: Uses Math::equal() for decimal comparison because price/quantity
     * accessors normalize trailing zeros (e.g., "26500") while reference values
     * may retain them (e.g., "26500.00000000"). String comparison would false-positive.
     *
     * Skip windows:
     *   - `opening`: reference values are not yet set during order placement.
     *   - `waping`: ApplyWapJob modifies TP price on the exchange and writes
     *     the new price back to the DB before reference_price is bumped —
     *     evaluating drift here would false-fire against our own WAP.
     *
     * Crucially NOT skipped:
     *   - `syncing`: the sync loop is the ONLY window where drift from a
     *     third-party modification (user editing qty/price on the exchange
     *     UI) is observable. apiSync() only reads from the exchange and
     *     mirrors the values locally — it never touches reference_*. If
     *     after apiSync the DB's price != reference_price, an external
     *     modification happened and correction must be dispatched from
     *     right here. Skipping during syncing closes the only window this
     *     can be caught: once the DB has been written, subsequent syncs
     *     see no dirty fields and the observer never fires again.
     */
    private function checkForOrderModification(Order $model, mixed $position): void
    {
        if (in_array($position->status, ['opening', 'waping'], true)) {
            return;
        }

        // Must have reference values to compare against
        if ($model->reference_price === null && $model->reference_quantity === null) {
            return;
        }

        // Check for price drift (both values must be non-null for comparison)
        $hasPriceDrift = $model->reference_price !== null
            && $model->price !== null
            && ! Math::equal($model->price, $model->reference_price);

        // Check for quantity drift using precise decimal comparison
        $hasQuantityDrift = $model->reference_quantity !== null
            && $model->quantity !== null
            && ! Math::equal($model->quantity, $model->reference_quantity);

        // Skip if no modification detected
        if (! $hasPriceDrift && ! $hasQuantityDrift) {
            return;
        }

        // Wrap the dedupe SELECT and the correction-job INSERT in the
        // trading prefix so both target `trading_steps`. Without the
        // wrap the dedupe queries the default `steps` table, never
        // finds a pending correction (because trading dispatched it),
        // and re-fires every observer cycle until the drift is healed.
        Steps::usingPrefix('trading', function () use ($model, $position, $hasPriceDrift, $hasQuantityDrift): void {
            // Deduplicate: skip if PrepareOrderCorrectionJob already pending for this order.
            $alreadyPending = Step::query()
                ->where('class', PrepareOrderCorrectionJob::class)
                ->whereRaw("JSON_EXTRACT(arguments, '$.orderId') = ?", [$model->id])
                ->whereIn('state', [Pending::class, Dispatched::class, Running::class])
                ->exists();

            if ($alreadyPending) {
                return;
            }

            $driftType = match (true) {
                $hasPriceDrift && $hasQuantityDrift => 'price and quantity',
                $hasPriceDrift => 'price',
                default => 'quantity',
            };

            // Resolve to the exchange-specific override when one exists (e.g.
            // Bitget uses modify-tpsl-order for algo correction instead of the
            // default cancel+recreate). Falls back to the base class when no
            // override is registered, preserving existing Binance behaviour.
            $resolvedClass = JobProxy::with($position->account)
                ->resolve(PrepareOrderCorrectionJob::class);

            Step::create([
                'class' => $resolvedClass,
                'queue' => 'positions',
                'priority' => 'high',
                'arguments' => [
                    'positionId' => $position->id,
                    'orderId' => $model->id,
                    'message' => "{$model->type} order #{$model->id} modified ({$driftType}) — correcting",
                ],
            ]);
        });
    }

    /**
     * Enforce maximum active order limits per type on a position.
     *
     * Throws NonNotifiableException when a rejection is detected so the
     * offending creation bubbles up through the calling step instead of
     * silently no-op'ing. Silent rejection was the historical behaviour
     * and produced the worst kind of bug on exchange: the remote order
     * could already be placed (apiPlace completes before DB insert),
     * leaving a live order with no local row to reconcile it.
     *
     * Raising an exception lets the surrounding job hit its normal
     * cancel-workflow fallback path — the position transitions to
     * 'failed' with a clear message rather than continuing blind.
     */
    private function enforceOrderLimits(Order $model): bool
    {
        $position = $model->position;

        if ($position === null) {
            return true;
        }

        $activeQuery = $position->orders()
            ->whereNotIn('status', self::INACTIVE_STATUSES);

        $allowed = match ($model->type) {
            'STOP-MARKET' => $this->allowIfNoActiveExists($activeQuery, 'STOP-MARKET'),
            'MARKET' => $this->allowIfNoActiveExists($activeQuery, 'MARKET'),
            'MARKET-CANCEL' => $this->allowIfNoActiveExists($activeQuery, 'MARKET-CANCEL'),
            'PROFIT-LIMIT', 'PROFIT-MARKET' => $this->allowIfNoActiveProfitExists($activeQuery),
            'LIMIT' => $this->allowIfLimitNotExceeded($activeQuery, $position),
            default => true,
        };

        if ($allowed) {
            return true;
        }

        $position->modelLog('order_creation_rejected', [
            'type' => $model->type,
            'side' => $model->side,
            'position_side' => $model->position_side,
            'price' => $model->price,
            'quantity' => $model->quantity,
        ], message: "{$model->type} order rejected — limit exceeded for position #{$position->id}");

        $position->appLog(
            event: 'order_rejected',
            message: "{$model->type} order rejected — limit exceeded for position #{$position->id}",
            severity: 'warning',
            metadata: [
                'type' => $model->type,
                'side' => $model->side,
                'price' => $model->price,
                'quantity' => $model->quantity,
            ]
        );

        // PROFIT-LIMIT and PROFIT-MARKET share the same cap (one TP per
        // position) — report a unified "PROFIT" group so callers and tests
        // don't have to branch on which flavor tripped the guard.
        $group = in_array($model->type, ['PROFIT-LIMIT', 'PROFIT-MARKET'], true)
            ? 'PROFIT'
            : $model->type;

        throw new NonNotifiableException(
            "{$group} order creation blocked for position #{$position->id} — active limit exceeded"
        );
    }

    private function allowIfNoActiveExists(mixed $query, string $type): bool
    {
        return ! (clone $query)->where('type', $type)->exists();
    }

    private function allowIfNoActiveProfitExists(mixed $query): bool
    {
        return ! (clone $query)->whereIn('type', ['PROFIT-LIMIT', 'PROFIT-MARKET'])->exists();
    }

    private function allowIfLimitNotExceeded(mixed $query, mixed $position): bool
    {
        $activeCount = (clone $query)->where('type', 'LIMIT')->count();

        return $activeCount < $position->total_limit_orders;
    }
}
