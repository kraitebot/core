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
final class PlaceLimitOrderJob extends BaseApiableJob
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
     * Verify the step can start.
     *
     * Idempotent-resume contract: a step may reach here either on its
     * first attempt (no exchange_order_id yet) OR on a retry after the
     * place succeeded but a later hook failed or the worker died
     * (exchange_order_id is already set). Both paths are valid starts —
     * the only genuine refusal is a terminal status (FILLED, CANCELLED,
     * EXPIRED, PARTIALLY_FILLED) where the step should never have been
     * picked up again.
     *
     * LAB #107 (2026-04-23 14:07) burned on the naive version of this
     * method: rung 3 placed, a retry triggered, startOrFail saw the
     * exchange_order_id and bailed → cascade → forced close at a worse
     * price than entry.
     */
    public function startOrFail(): bool
    {
        return in_array($this->limitOrder->status, ['NEW', 'PARTIALLY_FILLED'], true);
    }

    public function computeApiable()
    {
        $position = $this->limitOrder->position;
        $exchangeSymbol = $position->exchangeSymbol;

        // Idempotent resume: if the order already carries an exchange_order_id
        // the first attempt already placed it. Skip apiPlace so we don't
        // double-place on the exchange; doubleCheck() and complete() will
        // run against the confirmed exchange state.
        if ($this->limitOrder->exchange_order_id === null) {
            $this->limitOrder->apiPlace();
        }

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
            'error_message' => "Limit order L{$this->rungIndex} failed: ".$e->getMessage(),
        ]);
    }
}
