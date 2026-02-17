<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\Order;

use Kraite\Core\Abstracts\BaseApiableJob;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Models\Order;
use Throwable;

/**
 * PlaceLimitOrderJob (Atomic)
 *
 * Places a limit order on the exchange.
 * The Order record must already exist in the database (created by DispatchLimitOrdersJob).
 *
 * Flow:
 * 1. Load the Order record
 * 2. Place order on exchange via apiPlace()
 * 3. doubleCheck() verifies order was accepted (status=NEW)
 * 4. complete() sets reference_* fields from first sync
 */
class PlaceLimitOrderJob extends BaseApiableJob
{
    public Order $limitOrder;

    public int $rungIndex;

    public function __construct(int $orderId, int $rungIndex)
    {
        $this->limitOrder = Order::findOrFail($orderId);
        $this->rungIndex = $rungIndex;
    }

    public function assignExceptionHandler(): void
    {
        $this->exceptionHandler = BaseExceptionHandler::make(
            $this->limitOrder->position->account->apiSystem->canonical
        )->withAccount($this->limitOrder->position->account);
    }

    public function relatable()
    {
        return $this->limitOrder->position;
    }

    /**
     * Verify order is ready to be placed.
     */
    public function startOrFail(): bool
    {
        // Order must exist and be in NEW status (not yet placed)
        if ($this->limitOrder->status !== 'NEW') {
            return false;
        }

        // Order must not have an exchange_order_id yet
        if ($this->limitOrder->exchange_order_id !== null) {
            return false;
        }

        return true;
    }

    public function computeApiable()
    {
        $position = $this->limitOrder->position;
        $exchangeSymbol = $position->exchangeSymbol;

        // Place order on exchange
        $this->limitOrder->apiPlace();

        return [
            'position_id' => $position->id,
            'order_id' => $this->limitOrder->id,
            'rung_index' => $this->rungIndex,
            'trading_pair' => $exchangeSymbol->parsed_trading_pair,
            'direction' => $position->direction,
            'side' => $this->limitOrder->side,
            'price' => $this->limitOrder->price,
            'quantity' => $this->limitOrder->quantity,
            'message' => "Limit order L{$this->rungIndex} placed on exchange",
        ];
    }

    /**
     * Verify the limit order was accepted.
     */
    public function doubleCheck(): bool
    {
        $this->limitOrder->apiSync();
        $this->limitOrder->refresh();

        // Limit order is accepted if status is NEW (waiting to fill)
        // or PARTIALLY_FILLED (already getting filled)
        // or FILLED (price hit immediately - rare but possible)
        return in_array($this->limitOrder->status, ['NEW', 'PARTIALLY_FILLED', 'FILLED'], true);
    }

    /**
     * Set reference data from first sync.
     */
    public function complete(): void
    {
        // Set reference data from the order
        $this->limitOrder->updateSaving([
            'reference_price' => $this->limitOrder->price,
            'reference_quantity' => $this->limitOrder->quantity,
            'reference_status' => $this->limitOrder->status,
        ]);
    }

    /**
     * Handle exceptions during limit order placement.
     */
    public function resolveException(Throwable $e): void
    {
        $position = $this->limitOrder->position;

        // Log error to position
        $position->updateSaving([
            'error_message' => "Limit order L{$this->rungIndex} failed: " . $e->getMessage(),
        ]);
    }
}
