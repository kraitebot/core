<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\Order;

use Illuminate\Support\Facades\DB;
use Kraite\Core\Abstracts\BaseApiableJob;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Exceptions\NonNotifiableException;
use Kraite\Core\Jobs\Lifecycles\Position\ApplyWapJob;
use Kraite\Core\Models\ApiSnapshot;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\Math;
use Kraite\Core\Support\NotificationService;
use RuntimeException;
use StepDispatcher\Models\Step;
use StepDispatcher\Support\Steps;
use Throwable;

/**
 * CalculateWapAndModifyProfitOrderJob (Atomic)
 *
 * Calculates the Weighted Average Price (WAP) from the exchange's breakEvenPrice
 * and modifies the PROFIT order accordingly.
 *
 * Calculation:
 * 1. Read positions from ApiSnapshot (account-positions)
 * 2. Extract breakEvenPrice and positionAmt for the position
 * 3. Calculate new TP price: breakEvenPrice * (1 ± profit_percentage/100)
 * 4. Modify the profit order via apiModify()
 * 5. Update reference values to prevent correction loop
 *
 * Guards:
 * - Position must be in 'waping' status
 * - Profit order must exist
 * - profit_percentage must be configured
 */
// Intentionally NOT `final` — per-exchange overrides extend this class.
// Pint's `final_class` rule keeps trying to mark it final; if you re-run
// Pint and see `final` re-added, drop it again. The Feature test
// `CalculateWapExchangeClassLoadingTest` pins the contract.
class CalculateWapAndModifyProfitOrderJob extends BaseApiableJob
{
    public Position $position;

    public ?Order $profitOrder = null;

    /** Send-once latch for the WAP-applied notification (see dispatchWapAppliedNotification). */
    protected bool $wapNotificationDispatched = false;

    public ?string $breakEvenPrice = null;

    public ?string $positionQty = null;

    public ?string $intendedPrice = null;

    public ?string $intendedQty = null;

    public function __construct(int $positionId)
    {
        $this->position = Position::findOrFail($positionId);
    }

    public function assignExceptionHandler(): void
    {
        $this->exceptionHandler = BaseExceptionHandler::make(
            $this->position->account->apiSystem->canonical
        )->withAccount($this->position->account);
    }

    public function relatable()
    {
        return $this->position;
    }

    /**
     * Verify position is ready for WAP calculation.
     */
    public function startOrFail(): bool
    {
        // Position must be in 'waping' status (set by UpdatePositionStatusJob)
        if ($this->position->status !== 'waping') {
            return false;
        }

        // Must have a profit order to modify
        $this->profitOrder = $this->position->profitOrder();
        if ($this->profitOrder === null) {
            return false;
        }

        // Must have profit_percentage configured
        if ($this->position->profit_percentage === null) {
            return false;
        }

        return true;
    }

    public function computeApiable()
    {
        $scale = 8;

        // 1) Read the latest account-positions snapshot
        $positions = ApiSnapshot::getFrom($this->position->account, 'account-positions');

        // 2) Build position key and find in snapshot
        // Key format: "BTCUSDT:LONG" or "BTCUSDT:SHORT"
        $positionKey = $this->buildPositionKey();

        // Try keyed lookup first (for Binance format: symbol:direction)
        $positionFromExchange = null;
        if (is_array($positions)) {
            if (array_key_exists($positionKey, $positions)) {
                $positionFromExchange = $positions[$positionKey];
            } else {
                // Fallback: search by symbol (simpler format: just symbol key)
                $symbolKey = $this->position->parsed_trading_pair;
                if (array_key_exists($symbolKey, $positions)) {
                    $positionFromExchange = $positions[$symbolKey];
                }
            }
        }

        if ($positionFromExchange === null) {
            // Position gone from the exchange snapshot — either closed by a
            // concurrent workflow (TP/SL filled and close ran) or closed
            // externally by the operator. Not a real failure; resolve the
            // block quietly and let the close workflow / next sync reconcile.
            throw new NonNotifiableException(
                "Position {$positionKey} not found in account-positions snapshot — ".
                'likely closed by concurrent workflow or externally. Aborting WAP.'
            );
        }

        // 3) Extract breakEvenPrice and positionAmt
        $this->breakEvenPrice = (string) ($positionFromExchange['breakEvenPrice'] ?? '0');
        $rawQty = (string) ($positionFromExchange['positionAmt']
            ?? $positionFromExchange['size']
            ?? $positionFromExchange['qty']
            ?? '0');

        // Validate breakEvenPrice
        if (Math::lte($this->breakEvenPrice, '0')) {
            throw new RuntimeException(
                "Invalid breakEvenPrice={$this->breakEvenPrice} for position {$positionKey}. ".
                'Cannot calculate WAP.'
            );
        }

        // Validate quantity. A zero exchange qty with the position still in
        // the snapshot is the "just closed" race — same treatment as the
        // not-found branch above: non-notifiable, let close/sync reconcile.
        if (Math::equal($rawQty, '0')) {
            throw new NonNotifiableException(
                "Zero position quantity on exchange for {$positionKey} — ".
                'position likely closed mid-WAP. Aborting WAP.'
            );
        }

        // Absolute quantity (SHORT arrives negative on Binance)
        $this->positionQty = Math::lt($rawQty, '0')
            ? Math::mul($rawQty, '-1', $scale)
            : $rawQty;

        // Consistency gate: the snapshot's positionAmt must reflect every
        // FILLED order the DB already knows about. If the exchange reports
        // less quantity than our local view of market + filled DCA orders,
        // the matching engine has not yet committed the triggering fill
        // into breakEvenPrice — the WAP would be computed against stale
        // data. Fail the step so the parent retries with a fresh snapshot.
        $expectedQty = $this->expectedPositionQty();
        if (Math::lt($this->positionQty, $expectedQty, $scale)) {
            throw new RuntimeException(sprintf(
                'breakEvenPrice snapshot lags the local fill ledger for position #%d: exchange qty=%s, local expected=%s. Binance has not yet committed the fresh LIMIT fill. Retry.',
                $this->position->id,
                $this->positionQty,
                $expectedQty
            ));
        }

        // 4) Calculate target price
        $profitPct = (string) $this->position->profit_percentage;  // e.g. "0.350"
        $fraction = Math::div($profitPct, '100', $scale);          // -> "0.0035"

        $isLong = mb_strtoupper((string) $this->position->direction) === 'LONG';
        $multiplier = $isLong
            ? Math::add('1', $fraction, $scale)    // LONG: 1 + fraction
            : Math::sub('1', $fraction, $scale);   // SHORT: 1 - fraction

        $target = Math::mul($this->breakEvenPrice, $multiplier, $scale);

        // 5) Format price & quantity for exchange
        $formattedPrice = api_format_price($target, $this->position->exchangeSymbol);
        $formattedQty = api_format_quantity($this->positionQty, $this->position->exchangeSymbol);

        // 6) Capture old values for logging
        $oldQty = (string) ($this->profitOrder->quantity ?? '0');
        $oldPrice = (string) ($this->profitOrder->price ?? '0');

        // Remember what we SENT so doubleCheck() and failure diagnostics can
        // compare against what the exchange actually applied.
        $this->intendedPrice = $formattedPrice;
        $this->intendedQty = $formattedQty;

        // 7) Modify on exchange, sync, and if modify throws — sync anyway so
        // the error message reports the real exchange state rather than
        // leaving the operator wondering whether the modify partially applied.
        try {
            // Pass decimal strings through — the (float) casts that used
            // to live here truncated wide-precision values via PHP's
            // 14-digit float-to-string default before the mapper saw them.
            $this->profitOrder->apiModify($formattedQty, $formattedPrice);
        } catch (Throwable $e) {
            try {
                $this->profitOrder->apiSync();
            } catch (Throwable) {
                // Sync itself failed too — fall through with the original error
            }

            // Treat ignorable exchange responses as success. Binance's -5027
            // ("No need to modify the order") fires when the intended
            // price/qty match what's already on exchange — either because
            // this is a retry of a modify that actually landed, or because
            // the formatter rounded to the exchange's current values. Either
            // way the exchange state is exactly what we want, so fall
            // through to the normal success path so complete() can bump
            // reference values and dispatch the follow-up WAP. Without this
            // shortcut, we'd wrap the exception in RuntimeException, which
            // hides the -5027 from the framework's ignore-classifier (see
            // ApiExceptionHelpers::firstRequestExceptionIn chain walk) and
            // the step would fail for an outcome that is actually healthy.
            if ($this->externalIgnoreException($e)) {
                // Fall through — the catch block exits and the rest of
                // computeApiable continues as if the modify had succeeded.
            } else {
                throw new RuntimeException(sprintf(
                    'apiModify failed for profit order #%d (intended price=%s qty=%s; actual DB after sync: price=%s qty=%s status=%s). Original: %s',
                    $this->profitOrder->id,
                    $formattedPrice,
                    $formattedQty,
                    $this->profitOrder->price,
                    $this->profitOrder->quantity,
                    $this->profitOrder->status,
                    $e->getMessage()
                ), 0, $e);
            }
        }

        $this->profitOrder->apiSync();

        return [
            'position_id' => $this->position->id,
            'order_id' => $this->profitOrder->id,
            'trading_pair' => $this->position->parsed_trading_pair,
            'direction' => $this->position->direction,
            'break_even_price' => $this->breakEvenPrice,
            'profit_percentage' => $profitPct,
            'old_price' => $oldPrice,
            'new_price' => $this->profitOrder->price,
            'old_quantity' => $oldQty,
            'new_quantity' => $this->profitOrder->quantity,
            'message' => 'WAP calculated and profit order modified',
        ];
    }

    /**
     * Verify the profit order was modified successfully.
     *
     * Status alone isn't enough — Binance's PUT /fapi/v1/order is an in-place
     * modify that keeps the orderId and status, so a no-op / silent-reject
     * modify would leave the order at NEW and we'd think we succeeded. Also
     * verify the intended price and quantity actually landed (tolerance: one
     * tick on price, exact on qty since quantity_precision is respected).
     */
    public function doubleCheck(): bool
    {
        if ($this->profitOrder === null) {
            return false;
        }

        $this->profitOrder->refresh();

        if (! in_array($this->profitOrder->status, ['NEW', 'PARTIALLY_FILLED'], true)) {
            return false;
        }

        if ($this->intendedPrice !== null) {
            $tick = (string) ($this->position->exchangeSymbol->tick_size ?? '0');
            $actualPrice = (string) ($this->profitOrder->price ?? '0');
            $drift = Math::lt($actualPrice, $this->intendedPrice, 20)
                ? Math::sub($this->intendedPrice, $actualPrice, 20)
                : Math::sub($actualPrice, $this->intendedPrice, 20);

            if (Math::gt($tick, '0', 20) && Math::gt($drift, $tick, 20)) {
                return false;
            }
        }

        if ($this->intendedQty !== null) {
            $actualQty = (string) ($this->profitOrder->quantity ?? '0');
            if (! Math::equal($actualQty, $this->intendedQty, 8)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Update reference values to prevent correction loop.
     */
    public function complete(): void
    {
        if ($this->profitOrder === null) {
            return;
        }

        // Capture the pre-update reference values so the WAP notification
        // can report the before/after transition. After this method's
        // updateSaving() runs, reference_* is aligned to the new values
        // and the "old" side of the transition would be lost.
        $oldTpPrice = (string) ($this->profitOrder->reference_price ?? '0');
        $oldTpQuantity = (string) ($this->profitOrder->reference_quantity ?? '0');

        // CRITICAL: Update reference values to prevent OrderObserver from
        // detecting a modification and dispatching PrepareOrderCorrectionJob
        $this->profitOrder->updateSaving([
            'reference_quantity' => $this->profitOrder->quantity,
            'reference_price' => $this->profitOrder->price,
        ]);

        // Update position with WAP data
        $this->position->updateSaving([
            'quantity' => $this->profitOrder->quantity,
            'was_waped' => true,
            'waped_at' => now(),
        ]);

        $this->dispatchWapAppliedNotification($oldTpPrice, $oldTpQuantity);

        // Re-entry for sequential fills: any LIMIT with status=FILLED but
        // reference_status still != FILLED was skipped by observer dedup
        // while this WAP was running. Those fills are now unacked — sync
        // will not re-fire observer for them (no field change), so dispatch
        // a follow-up ApplyWap so their qty gets reflected in the TP. The
        // small dispatch_after delay ensures the current step's state has
        // transitioned to Completed before the dedup check runs in observer.
        //
        // We claim the unacked fills here by bulk-bumping reference_status
        // to FILLED in the same transaction that dispatches the follow-up
        // WAP. Without this, the next sync-orders tick would see the same
        // unacked LIMITs and dispatch a second redundant WAP on top of our
        // self-dispatched one — harmless but wasteful. Bulk UPDATE skips
        // the observer, which is what we want (the "need WAP" signal is
        // already captured by the Step we're about to create below).
        $unackedLimitsQuery = $this->position->orders()
            ->where('type', 'LIMIT')
            ->where('status', 'FILLED')
            ->where(function ($q) {
                $q->where('reference_status', '!=', 'FILLED')
                    ->orWhereNull('reference_status');
            });

        $hasUnackedFills = $unackedLimitsQuery->exists();

        if ($hasUnackedFills) {
            // Pin the StepDispatcher prefix explicitly. The follow-up
            // Step::create MUST land in `trading_steps` so the
            // OrderObserver's prefix-scoped dedupe can see it; relying
            // on the ambient context restored by BaseStepJob::handle()
            // works in production today but is fragile to future
            // framework changes. Wrapping here matches the prefix
            // discipline of every other trading-step dispatch site.
            Steps::usingPrefix('trading', function () use ($unackedLimitsQuery): void {
                // Ack and follow-up step live or die together. The ack IS
                // the claim on these fills — once reference_status=FILLED
                // commits, neither the observer nor the sync-orders
                // recovery sweep will ever look at them again, so the
                // ApplyWap step is the only carrier of the "needs WAP"
                // signal. An ack that outlived a failed step insert
                // permanently silenced the fills and left the TP sized
                // for the earlier fill set.
                DB::transaction(function () use ($unackedLimitsQuery): void {
                    (clone $unackedLimitsQuery)->update(['reference_status' => 'FILLED']);

                    Step::create([
                        'class' => ApplyWapJob::class,
                        'queue' => 'positions',
                        'arguments' => [
                            'positionId' => $this->position->id,
                            'message' => 'Follow-up WAP for LIMIT fills that arrived during the prior WAP run',
                        ],
                        'dispatch_after' => now()->addSeconds(3),
                    ]);
                });
            });
        }
    }

    /**
     * Handle exceptions during WAP calculation.
     */
    public function resolveException(Throwable $e): void
    {
        $this->position->updateSaving([
            'error_message' => 'WAP calculation failed: '.$e->getMessage(),
        ]);
    }

    /**
     * Build the position key for `account-positions` snapshot lookup.
     *
     * The snapshot is keyed by `<symbol>:<positionSide>` per
     * `MapsPositionsQuery::resolveQueryPositionsResponse`. Binance returns
     * `positionSide` differently per trading mode:
     *
     *   - Hedge mode (`accounts.on_hedge_mode = true`):  LONG / SHORT
     *   - One-way mode (`on_hedge_mode = false`):        BOTH
     *
     * Always-using-direction (the prior implementation) silently broke
     * WAP on every one-way-mode account: the lookup never matched the
     * `:BOTH` key, the job threw NonNotifiableException, and the
     * position was left with stale TP at the original opening price.
     * Pinned by `CalculateWapBuildPositionKeyTest`.
     */
    protected function buildPositionKey(): string
    {
        $symbol = $this->position->parsed_trading_pair;
        $segment = $this->position->account?->on_hedge_mode
            ? mb_strtoupper((string) $this->position->direction)
            : 'BOTH';

        return "{$symbol}:{$segment}";
    }

    /**
     * Fire the `position_wap_applied` Pushover notification (priority 1 /
     * high — bypasses quiet hours) so the owner sees that DCA fills have
     * aggregated and the TP has been repositioned. Cache-throttled at 30s
     * per position so rapid successive fills in the same tick don't
     * double-ping, but the follow-up-WAP mechanism will still surface a
     * second notification if it fires outside the 30s window.
     */
    protected function dispatchWapAppliedNotification(string $oldTpPrice, string $oldTpQuantity): void
    {
        // Send-once per job run. The Bitget variant notifies inline from
        // computeApiable (added after the 2026-05-02 production silence —
        // root cause of the completion-hook miss never established), and
        // the inherited complete() notifies again ms later. Deduplication
        // used to depend entirely on the notification-side 30s cache
        // throttle; a cache hiccup meant a double ping. One-notification-
        // per-WAP is now a property of the job itself, and the first call
        // wins — it carries the accurate pre-update "old" values.
        if ($this->wapNotificationDispatched) {
            return;
        }

        if ($this->profitOrder === null) {
            return;
        }

        $this->wapNotificationDispatched = true;

        $user = $this->position->account->user ?? null;

        if (! $user) {
            return;
        }

        NotificationService::send(
            user: $user,
            canonical: 'position_wap_applied',
            referenceData: [
                'token' => $this->position->exchangeSymbol?->token,
                'pair' => $this->position->parsed_trading_pair,
                'direction' => mb_strtoupper((string) $this->position->direction),
                'position_id' => (int) $this->position->id,
                'old_tp_price' => $oldTpPrice,
                'new_tp_price' => (string) $this->profitOrder->price,
                'old_tp_quantity' => $oldTpQuantity,
                'new_tp_quantity' => (string) $this->profitOrder->quantity,
                'break_even_price' => $this->breakEvenPrice,
            ],
            relatable: $this->position,
            cacheKeys: ['position' => $this->position->id],
        );
    }

    /**
     * Sum of filled order quantities we know locally — market entry plus
     * every FILLED DCA LIMIT order the DB has seen. This is the minimum
     * the exchange's positionAmt must reflect for the WAP math to be using
     * a trustworthy breakEvenPrice. Short positions are represented by the
     * absolute quantity (same convention as $this->positionQty).
     */
    private function expectedPositionQty(): string
    {
        return (string) $this->position->orders()
            ->whereIn('type', ['MARKET', 'LIMIT'])
            ->where('status', 'FILLED')
            ->sum('quantity');
    }
}
