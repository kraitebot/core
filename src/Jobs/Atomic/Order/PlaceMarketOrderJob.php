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

        $quantity = $exchangeSymbol->getQuantityForAmount($notional, respectMinNotional: false);

        if ($quantity === '0') {
            throw new RuntimeException(
                "Failed to calculate quantity for notional {$notional} at price {$exchangeSymbol->mark_price}"
            );
        }

        $side = $direction === 'LONG' ? 'BUY' : 'SELL';
        $markPrice = api_format_price((string) $exchangeSymbol->mark_price, $exchangeSymbol);

        // Reuse any Order row an aborted prior attempt left behind (no
        // exchange_order_id means it never reached the exchange). Prevents
        // orphan rows accumulating on retries.
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
}
