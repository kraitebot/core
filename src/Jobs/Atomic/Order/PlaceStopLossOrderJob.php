<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\Order;

use Kraite\Core\Abstracts\BaseApiableJob;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Trading\Kraite;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
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

        // Must have at least one limit order to anchor from
        if ($this->position->lastLimitOrder() === null) {
            return false;
        }

        // SL is snapshotted onto positions.stop_market_percentage at
        // PreparePositionDataJob — must be present before placement.
        if ($this->position->stop_market_percentage === null) {
            return false;
        }

        return true;
    }

    public function computeApiable()
    {
        $exchangeSymbol = $this->position->exchangeSymbol;
        $direction = $this->position->direction;

        // Side is opposite to close position
        $side = $direction === 'LONG' ? 'SELL' : 'BUY';

        // Get the last limit order (L4 - highest quantity) as anchor
        $lastLimitOrder = $this->position->lastLimitOrder();
        $anchorPrice = $lastLimitOrder->price;

        // Calculate stop-loss order data
        $stopLossData = Kraite::calculateStopLossOrder(
            direction: $direction,
            anchorPrice: $anchorPrice,
            stopPercent: $this->position->stop_market_percentage,
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
            'stop_percentage' => $this->position->stop_market_percentage,
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
