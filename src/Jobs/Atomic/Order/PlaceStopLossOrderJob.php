<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\Order;

use Kraite\Core\Abstracts\BaseApiableJob;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\TpSlResolver;
use Kraite\Core\Trading\Kraite;
use Throwable;

/**
 * PlaceStopLossOrderJob (Atomic)
 *
 * Creates and places a stop-loss order on the exchange.
 *
 * Calculation:
 * - Anchor price: Last limit order price (highest rung index)
 * - Percentage: position.stop_market_percentage (snapshotted at PreparePositionDataJob via TpSlResolver)
 * - Quantity: position.quantity (stored for reference)
 * - Side: opposite of entry (LONG → SELL, SHORT → BUY)
 *
 * Flow:
 * 1. Query last limit order price from orders table
 * 2. Calculate stop price using Kraite::calculateStopLossOrder()
 * 3. Create Order record with type=STOP-MARKET
 * 4. Place order on exchange via apiPlace()
 * 5. doubleCheck() verifies order was accepted
 * 6. complete() sets reference_* fields
 *
 * Exchange-specific behavior:
 * - Binance: Uses closePosition=true (auto-closes entire position when
 *   triggered, regardless of quantity). No need to update stop-loss during WAP.
 * - Other exchanges: May use explicit quantity (TBD).
 */
final class PlaceStopLossOrderJob extends BaseApiableJob
{
    public Position $position;

    public ?Order $stopLossOrder = null;

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
     * Verify position is ready for stop-loss order.
     */
    public function startOrFail(): bool
    {
        // Position must be in an active status (opening, active, syncing, etc.)
        if (! in_array($this->position->status, $this->position->activeStatuses(), true)) {
            return false;
        }

        // Must have quantity
        if ($this->position->quantity === null) {
            return false;
        }

        // Must have a resolvable anchor price. In martingale mode the deepest
        // LIMIT rung is the worst-case fill we hedge against; in simple-trade
        // mode (total_limit_orders === 0) the MARKET fill is the only fill,
        // so `opening_price` is the canonical anchor instead.
        if ($this->resolveAnchorPrice() === null) {
            return false;
        }

        // SL percentage must resolve to a non-null value. Snapshot is the
        // canonical source; resolveStopLossPercentage() falls back to the
        // account default for positions opened before the snapshot column
        // existed (or for half-baked deploys).
        if ($this->resolveStopLossPercentage() === null) {
            return false;
        }

        return true;
    }

    /**
     * Resolve the anchor price for the stop-loss calculation.
     *
     *   total_limit_orders === 0  → opening_price (MARKET fill, simple-trade mode)
     *   total_limit_orders >= 1   → lastLimitOrder()->price (deepest DCA rung)
     *
     * Returns null when the relevant anchor isn't available yet — the caller
     * (`startOrFail`) treats that as "not ready" rather than throwing on a
     * downstream null deref inside `computeApiable`.
     */
    public function resolveAnchorPrice(): ?string
    {
        if ((int) $this->position->total_limit_orders === 0) {
            $opening = $this->position->opening_price;

            if ($opening === null || $opening === '') {
                return null;
            }

            return (string) $opening;
        }

        $lastLimitOrder = $this->position->lastLimitOrder();

        if ($lastLimitOrder === null) {
            return null;
        }

        return (string) $lastLimitOrder->price;
    }

    /**
     * Resolve the SL percentage. Prefer the position-level snapshot set
     * by PreparePositionDataJob; fall back to a live resolve through the
     * same TpSlResolver if the snapshot is missing — same answer the
     * resolver would have produced at prepare-time, just deferred. Keeps
     * SL placement working across pre-migration positions and any future
     * worker-cache mismatch where PrepareJob ran old code.
     */
    public function resolveStopLossPercentage(): ?string
    {
        $snapshot = $this->position->stop_market_percentage;
        if ($snapshot !== null && $snapshot !== '') {
            return (string) $snapshot;
        }

        $account = $this->position->account;
        if ($account->stop_market_initial_percentage === null) {
            return null;
        }

        return TpSlResolver::resolve(
            symbolValue: $this->position->exchangeSymbol?->stop_market_percentage,
            accountOverride: (bool) $account->override_sl,
            accountValue: (string) $account->stop_market_initial_percentage,
        );
    }

    public function computeApiable()
    {
        $exchangeSymbol = $this->position->exchangeSymbol;
        $direction = $this->position->direction;
        $stopPercent = $this->resolveStopLossPercentage();

        // Side is opposite to close position
        $side = $direction === 'LONG' ? 'SELL' : 'BUY';

        // Anchor source depends on mode: martingale uses the deepest LIMIT
        // rung's scheduled price (worst-case projected fill); simple-trade
        // uses the MARKET fill price (the only fill in that mode).
        $anchorPrice = $this->resolveAnchorPrice();

        // Calculate stop-loss order data
        $stopLossData = Kraite::calculateStopLossOrder(
            direction: $direction,
            anchorPrice: $anchorPrice,
            stopPercent: $stopPercent,
            currentQty: $this->position->quantity,
            exchangeSymbol: $exchangeSymbol,
        );

        // Create Order record
        $this->stopLossOrder = Order::create([
            'position_id' => $this->position->id,
            'type' => 'STOP-MARKET',
            'status' => 'NEW',
            'side' => $side,
            'position_side' => $direction,
            'price' => $stopLossData['price'],
            'quantity' => $stopLossData['quantity'],
        ]);

        // Place on exchange
        $this->stopLossOrder->apiPlace();

        return [
            'position_id' => $this->position->id,
            'order_id' => $this->stopLossOrder->id,
            'trading_pair' => $exchangeSymbol->parsed_trading_pair,
            'direction' => $direction,
            'side' => $side,
            'price' => $stopLossData['price'],
            'quantity' => $stopLossData['quantity'],
            'anchor_price' => $anchorPrice,
            'stop_percentage' => $stopPercent,
            'message' => 'Stop-loss order placed on exchange',
        ];
    }

    /**
     * Verify the stop-loss order was accepted.
     */
    public function doubleCheck(): bool
    {
        if ($this->stopLossOrder === null) {
            return false;
        }

        $this->stopLossOrder->apiSync();
        $this->stopLossOrder->refresh();

        // Stop-loss order is accepted if status is NEW (waiting) or FILLED (triggered immediately)
        return in_array($this->stopLossOrder->status, ['NEW', 'PARTIALLY_FILLED', 'FILLED'], true);
    }

    /**
     * Set reference data from first sync.
     */
    public function complete(): void
    {
        if ($this->stopLossOrder === null) {
            return;
        }

        $this->stopLossOrder->updateSaving([
            'reference_price' => $this->stopLossOrder->price,
            'reference_quantity' => $this->stopLossOrder->quantity,
            'reference_status' => $this->stopLossOrder->status,
        ]);

        $this->position->appLog(
            event: 'stop_loss_placed',
            message: "Stop-loss order placed at \${$this->stopLossOrder->price}",
            metadata: [
                'order_id' => $this->stopLossOrder->id,
                'price' => $this->stopLossOrder->price,
                'quantity' => $this->stopLossOrder->quantity,
            ]
        );
    }

    /**
     * Handle exceptions during stop-loss order placement.
     */
    public function resolveException(Throwable $e): void
    {
        $this->position->updateSaving([
            'error_message' => 'Stop-loss order failed: '.$e->getMessage(),
        ]);
    }
}
