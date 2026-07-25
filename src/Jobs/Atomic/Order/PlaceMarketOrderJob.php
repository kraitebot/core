<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\Order;

use Kraite\Core\Abstracts\BaseApiableJob;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\Math;
use Kraite\Core\Trading\Kraite;
use RuntimeException;
use Throwable;

/**
 * PlaceMarketOrderJob (Atomic)
 *
 * Places the market entry order for a position.
 *
 * Preconditions (set by PreparePositionDataJob):
 * - position.status = 'opening'
 * - position.margin is set
 * - position.leverage is set
 * - position.exchange_symbol_id is set
 *
 * Flow:
 * 1. Compute market order parameters (side, qty, etc.)
 * 2. Create Order record (type=MARKET, status=NEW)
 * 3. Place order on exchange via apiPlace()
 * 4. doubleCheck() verifies order was filled
 * 5. complete() updates position with opening data
 */
final class PlaceMarketOrderJob extends BaseApiableJob
{
    public Position $position;

    public ?Order $marketOrder = null;

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
     * Refuse a new exchange entry when trading readiness changed after the
     * opening workflow was queued. An entry already accepted by the exchange
     * must keep reconciling so existing exposure is never abandoned.
     */
    public function startOrStop(): bool
    {
        if ($this->position->account->fresh()?->isReadyToTrade() === true) {
            return true;
        }

        return $this->position->orders()
            ->where('type', 'MARKET')
            ->whereNotNull('exchange_order_id')
            ->exists();
    }

    /**
     * Verify position is ready for market order.
     *
     * On retry, restore $this->marketOrder from DB if a prior attempt already
     * created + placed it. Without this, a retry creates a fresh Order row and
     * re-calls apiPlace(), which would place a duplicate market order on the
     * exchange. PlaceLimitOrderJob guards the same way via exchange_order_id.
     */
    public function startOrFail(): bool
    {
        if ($this->position->status !== 'opening') {
            return false;
        }

        if ($this->position->margin === null) {
            return false;
        }

        $existing = $this->position->orders()
            ->where('type', 'MARKET')
            ->latest('id')
            ->first();

        if ($existing !== null) {
            $this->marketOrder = $existing;
        }

        return true;
    }

    public function computeApiable()
    {
        // Retry path: a prior attempt already placed the market order. Skip
        // re-placement; doubleCheck() + complete() will run against the
        // existing order and finalise normally.
        if ($this->marketOrder !== null && $this->marketOrder->exchange_order_id !== null) {
            return [
                'position_id' => $this->position->id,
                'order_id' => $this->marketOrder->id,
                'trading_pair' => $this->position->exchangeSymbol->parsed_trading_pair,
                'direction' => $this->position->direction,
                'side' => $this->marketOrder->side,
                'quantity' => $this->marketOrder->quantity,
                'message' => 'Market order already placed on prior attempt — skipping re-placement',
                'exchange_order_id' => $this->marketOrder->exchange_order_id,
            ];
        }

        $exchangeSymbol = $this->position->exchangeSymbol;
        $direction = $this->position->direction;
        $leverage = $this->position->leverage;

        $divider = get_market_order_amount_divider($this->position->total_limit_orders);
        $margin = (string) $this->position->margin;
        $notional = Math::div(Math::mul($margin, $leverage), $divider);

        $quantity = $exchangeSymbol->getQuantityForAmount($notional, respectMinNotional: true);

        if ($quantity === '0') {
            throw new RuntimeException(
                "Failed to calculate quantity for notional {$notional} at price {$exchangeSymbol->mark_price}"
            );
        }

        // Reuse any Order row an aborted prior attempt left behind (no
        // exchange_order_id means it never reached the exchange). Prevents
        // orphan rows accumulating on retries.
        $side = $direction === 'LONG' ? 'BUY' : 'SELL';

        if ($this->marketOrder === null) {
            $this->marketOrder = Order::create([
                'position_id' => $this->position->id,
                'type' => 'MARKET',
                'status' => 'NEW',
                'side' => $side,
                'position_side' => $direction,
                'quantity' => $quantity,
            ]);
        }

        $placedQuantity = (string) $this->marketOrder->quantity;

        if ($exchangeSymbol->isQuantityBelowMinNotional($placedQuantity)) {
            throw new RuntimeException(
                "Market order quantity {$placedQuantity} fell below minimum notional at the latest mark price"
            );
        }

        Kraite::calculateLimitOrdersData(
            totalLimitOrders: $this->position->total_limit_orders,
            direction: (string) $direction,
            referencePrice: (string) $exchangeSymbol->mark_price,
            marketOrderQty: $placedQuantity,
            exchangeSymbol: $exchangeSymbol,
            limitQuantityMultipliers: $exchangeSymbol->limit_quantity_multipliers,
        );

        $markPrice = api_format_price((string) $exchangeSymbol->mark_price, $exchangeSymbol);
        $apiResponse = $this->marketOrder->apiPlace();

        // An interrupted prior attempt may have persisted a quantity calculated
        // from an older mark price. Report the quantity actually sent.
        $actualNotional = $exchangeSymbol->getAmountForQuantity($placedQuantity);

        return [
            'position_id' => $this->position->id,
            'order_id' => $this->marketOrder->id,
            'trading_pair' => $exchangeSymbol->parsed_trading_pair,
            'direction' => $direction,
            'side' => $this->marketOrder->side,
            'quantity' => $placedQuantity,
            'estimated_price' => $markPrice,
            'margin' => $margin,
            'notional' => $actualNotional,
            'message' => 'Market order placed on exchange',
            'exchange_order_id' => $apiResponse->result['orderId'] ?? null,
        ];
    }

    /**
     * Verify the market order was filled.
     */
    public function doubleCheck(): bool
    {
        if (! $this->marketOrder) {
            return false;
        }

        $this->marketOrder->apiSync();
        $this->marketOrder->refresh();

        return $this->marketOrder->status === 'FILLED';
    }

    /**
     * Update position with opening data after market order fills.
     */
    public function complete(): void
    {
        if (! $this->marketOrder) {
            return;
        }

        // Update position with actual fill data
        // Note: status stays 'opening' - workflow continues with limit orders
        $this->position->updateSaving([
            'quantity' => $this->marketOrder->quantity,
            'opening_price' => $this->marketOrder->price,
            'opened_at' => now(),
        ]);

        // Set reference data from first sync (captures actual fill values)
        $this->marketOrder->updateSaving([
            'reference_price' => $this->marketOrder->price,
            'reference_quantity' => $this->marketOrder->quantity,
            'reference_status' => $this->marketOrder->status,
        ]);

        $this->position->appLog(
            event: 'market_order_placed',
            message: "Market order placed — {$this->marketOrder->quantity} {$this->position->exchangeSymbol->parsed_trading_pair} at \${$this->marketOrder->price}",
            metadata: [
                'order_id' => $this->marketOrder->id,
                'quantity' => $this->marketOrder->quantity,
                'price' => $this->marketOrder->price,
            ]
        );
    }

    /**
     * Handle exceptions during market order placement.
     */
    public function resolveException(Throwable $e): void
    {
        // Log error to position
        $this->position->updateSaving([
            'error_message' => $e->getMessage(),
        ]);
    }

    /**
     * Hard pre-fill cooldown gate.
     *
     * `apiSystem->inCooldown()` is set by ApiRequestLogObserver::triggerExchangeCooldownIfNeeded
     * when 503/504 instability is detected. The throttler's
     * `isSafeToMakeRequest` reads cache-based signals (IP ban / rate-limit
     * proximity) but does NOT consult the DB-side cooldown column — so a
     * market order dispatched moments before instability was detected would
     * otherwise fire mid-cooldown and create exposure during a degraded
     * exchange. Routing through `shouldStartOrThrottle` reuses the existing
     * rescheduleWithoutRetry path (no retry-budget burn while waiting for
     * cooldown to lift).
     *
     * Limit ladder, SL, TP and reconciliation steps deliberately do NOT
     * carry this gate — they protect existing exposure and must run even
     * during exchange cooldown.
     */
    protected function shouldStartOrThrottle(): bool
    {
        if ($this->position->account->apiSystem->inCooldown()) {
            $this->jobBackoffSeconds = 60;
            $this->jobBackoffMs = 0;

            return false;
        }

        return parent::shouldStartOrThrottle();
    }
}
