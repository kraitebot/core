<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\Order;

use Kraite\Core\Abstracts\BaseApiableJob;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\Math;
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
class PlaceMarketOrderJob extends BaseApiableJob
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
     * Verify position is ready for market order.
     */
    public function startOrFail(): bool
    {
        // Position must be in 'opening' status (set by PreparePositionDataJob)
        if ($this->position->status !== 'opening') {
            return false;
        }

        // Margin must be set (set by PreparePositionDataJob)
        if ($this->position->margin === null) {
            return false;
        }

        return true;
    }

    public function computeApiable()
    {
        $exchangeSymbol = $this->position->exchangeSymbol;
        $direction = $this->position->direction;
        $leverage = $this->position->leverage;

        // 1. Calculate market order notional using divider formula
        // divider = 2^(totalLimitOrders + 1) e.g., 4 limits = 32
        $divider = get_market_order_amount_divider($this->position->total_limit_orders);
        $margin = (string) $this->position->margin;
        $notional = Math::div(Math::mul($margin, $leverage), $divider);

        // 2. Get quantity using trait method (uses mark_price set by VerifyOrderNotionalJob)
        $quantity = $exchangeSymbol->getQuantityForAmount($notional, respectMinNotional: false);

        if ($quantity === '0') {
            throw new RuntimeException(
                "Failed to calculate quantity for notional {$notional} at price {$exchangeSymbol->mark_price}"
            );
        }

        // 3. Determine side from direction
        $side = $direction === 'LONG' ? 'BUY' : 'SELL';
        $markPrice = api_format_price((string) $exchangeSymbol->mark_price, $exchangeSymbol);

        // 4. Create Order record (reference_* fields set in complete() after first sync)
        $this->marketOrder = Order::create([
            'position_id' => $this->position->id,
            'type' => 'MARKET',
            'status' => 'NEW',
            'side' => $side,
            'position_side' => $direction,
            'quantity' => $quantity,
        ]);

        // 5. Place order on exchange
        $apiResponse = $this->marketOrder->apiPlace();

        // Calculate actual notional for response
        $actualNotional = $exchangeSymbol->getAmountForQuantity($quantity);

        return [
            'position_id' => $this->position->id,
            'order_id' => $this->marketOrder->id,
            'trading_pair' => $exchangeSymbol->parsed_trading_pair,
            'direction' => $direction,
            'side' => $side,
            'quantity' => $quantity,
            'estimated_price' => $markPrice,
            'margin' => Math::div($notional, $leverage),
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
}
