<?php

declare(strict_types=1);

namespace Kraite\Core\Observers;

use Illuminate\Support\Str;
use Kraite\Core\Exceptions\NonNotifiableException;
use Kraite\Core\Jobs\Lifecycles\Order\PrepareOrderCorrectionJob;
use Kraite\Core\Jobs\Lifecycles\Position\ApplyWapJob;
use Kraite\Core\Jobs\Lifecycles\Position\ClosePositionJob;
use Kraite\Core\Jobs\Lifecycles\Position\PreparePositionReplacementJob;
use Kraite\Core\Models\Order;
use Kraite\Core\Support\Math;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Dispatched;
use StepDispatcher\States\Pending;
use StepDispatcher\States\Running;

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

        // PROFIT/STOP orders: FILLED triggers close, CANCELLED/EXPIRED triggers replacement
        if (in_array($model->type, self::CLOSE_TRIGGER_TYPES, true)) {
            match ($model->status) {
                'FILLED' => $this->dispatchClosePosition($model, $position),
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
    }

    private function dispatchClosePosition(Order $model, mixed $position): void
    {
        // Deduplicate: skip if ClosePositionJob already pending/running for this position.
        // Prevents a second close workflow when both TP and SL reach FILLED in the same
        // sync cycle — the second run would collide with the first and mark the
        // position 'failed' on the "already closing/closed" guard.
        $alreadyPending = Step::query()
            ->where('class', ClosePositionJob::class)
            ->whereRaw("JSON_EXTRACT(arguments, '$.positionId') = ?", [$position->id])
            ->whereIn('state', [Pending::class, Dispatched::class, Running::class])
            ->exists();

        if ($alreadyPending) {
            return;
        }

        $model->updateSaving(['reference_status' => 'FILLED']);

        // Set status to 'closing' immediately so SyncPositionOrdersJob
        // doesn't override it to 'active' before ClosePositionJob runs
        $position->updateToClosing();

        Step::create([
            'class' => ClosePositionJob::class,
            'queue' => 'positions',
            'arguments' => [
                'positionId' => $position->id,
                'message' => "{$model->type} order #{$model->id} filled — closing position",
            ],
            'child_block_uuid' => (string) Str::uuid(),
        ]);
    }

    private function dispatchPositionReplacement(Order $model, mixed $position): void
    {
        // NOTE: Do NOT update reference_status here.
        // SmartReplaceOrdersJob needs to find orders where reference_status != status
        // to know which orders need recreation. RecreateCancelledOrderJob::complete()
        // updates reference_status after successful recreation.

        // Deduplicate: skip if PreparePositionReplacementJob already pending for this position.
        // Prevents multiple dispatches when several orders are cancelled at once.
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
            'arguments' => [
                'positionId' => $position->id,
                'triggerStatus' => $model->status,
                'message' => "{$model->type} order #{$model->id} {$action} — preparing replacement",
            ],
            'child_block_uuid' => (string) Str::uuid(),
        ]);
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
            'arguments' => [
                'positionId' => $position->id,
                'message' => "LIMIT order #{$model->id} filled — applying WAP",
            ],
            'child_block_uuid' => (string) Str::uuid(),
        ]);
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
     * Drift detection only runs when the position is in a stable 'active' state.
     * During transitional states (opening / waping / syncing) our own workflows
     * are intentionally mutating order price/quantity — the reference-vs-actual
     * window is expected to diverge momentarily and will re-align by the time
     * the state settles back to 'active'. Evaluating drift here would false-fire
     * a correction against our own in-flight WAP / opening / sync.
     */
    private function checkForOrderModification(Order $model, mixed $position): void
    {
        // Skip drift detection while a workflow we own is intentionally
        // mutating orders — the reference-vs-actual window diverges during
        // opening (placement + formatting), waping (TP modify + sync), and
        // syncing (reconciliation rewrites price/qty). Any "drift" observed
        // in those phases is our own, not a third-party modification. Drift
        // checking resumes once the position settles back into active/new.
        if (in_array($position->status, ['opening', 'waping', 'syncing'], true)) {
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

        Step::create([
            'class' => PrepareOrderCorrectionJob::class,
            'queue' => 'positions',
            'arguments' => [
                'positionId' => $position->id,
                'orderId' => $model->id,
                'message' => "{$model->type} order #{$model->id} modified ({$driftType}) — correcting",
            ],
            'child_block_uuid' => (string) Str::uuid(),
        ]);
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
