<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\Order;

use Kraite\Core\Abstracts\BaseApiableJob;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Trading\Exchange\Exchange;
use Kraite\Core\Trading\Kraite;
use RuntimeException;
use Throwable;

/**
 * PlaceProfitOrderJob (Atomic)
 *
 * Creates and places a take-profit order on the exchange.
 *
 * Calculation:
 * - Reference price: position.opening_price (market order fill price)
 * - Percentage: position.profit_percentage
 * - Quantity: position.quantity (market order quantity)
 * - Side: opposite of entry (LONG → SELL, SHORT → BUY)
 *
 * Flow:
 * 1. Calculate profit price using Kraite::calculateProfitOrder()
 * 2. Create Order record with type=PROFIT-LIMIT
 * 3. Place order on exchange via apiPlace()
 * 4. doubleCheck() verifies order was accepted
 * 5. complete() sets reference_* fields
 */
final class PlaceProfitOrderJob extends BaseApiableJob
{
    public Position $position;

    public ?Order $profitOrder = null;

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
     * Verify position is ready for profit order.
     *
     * On retry, restore $this->profitOrder from DB if a prior attempt already
     * placed it. Without this, a worker death between exchange-accept and
     * local reference-field commit would cause the next attempt to create a
     * fresh Order row and re-call apiPlace() — placing a duplicate TP on the
     * exchange. Same idempotency shape as PlaceMarketOrderJob and
     * PlaceLimitOrderJob.
     */
    public function startOrFail(): bool
    {
        // Position must be in an active status (opening, active, syncing, etc.)
        if (! in_array($this->position->status, $this->position->activeStatuses(), true)) {
            return false;
        }

        // Must have opening_price (market order filled)
        if ($this->position->opening_price === null) {
            return false;
        }

        // Must have quantity
        if ($this->position->quantity === null) {
            return false;
        }

        // Must have profit_percentage
        if ($this->position->profit_percentage === null) {
            return false;
        }

        // Idempotency: restore the existing active TP order if a prior
        // attempt placed one. CANCELLED/EXPIRED/REJECTED rows are excluded
        // because they represent prior attempts no longer on the book — a
        // fresh placement is required in that case.
        $existing = $this->position->orders()
            ->where('type', 'PROFIT-LIMIT')
            ->whereIn('status', ['NEW', 'PARTIALLY_FILLED', 'FILLED'])
            ->latest('id')
            ->first();

        if ($existing !== null) {
            $this->profitOrder = $existing;
        }

        return true;
    }

    public function computeApiable()
    {
        // Retry path: a prior attempt already placed the TP. Skip
        // re-placement; doubleCheck() + complete() will run against the
        // existing order and finalise normally. Same shape as
        // PlaceMarketOrderJob.
        if ($this->profitOrder !== null && $this->profitOrder->exchange_order_id !== null) {
            return [
                'position_id' => $this->position->id,
                'order_id' => $this->profitOrder->id,
                'trading_pair' => $this->position->exchangeSymbol->parsed_trading_pair,
                'direction' => $this->position->direction,
                'side' => $this->profitOrder->side,
                'price' => $this->profitOrder->price,
                'quantity' => $this->profitOrder->quantity,
                'exchange_order_id' => $this->profitOrder->exchange_order_id,
                'message' => 'Profit order already placed on prior attempt — skipping re-placement',
            ];
        }

        $exchangeSymbol = $this->position->exchangeSymbol;
        $direction = $this->position->direction;
        $canonical = $this->position->account->apiSystem->canonical;

        // Side is opposite to close position
        $side = $direction === 'LONG' ? 'SELL' : 'BUY';

        // Fetch fresh mark price so TP re-anchors if price already passed
        $mapper = Exchange::forCanonical($canonical)->mapper();
        $properties = $mapper->prepareQueryMarkPriceProperties($exchangeSymbol);
        $response = Account::admin($canonical)->withApi()->getMarkPrice($properties);
        $markPrice = $mapper->resolveQueryMarkPriceResponse($response);

        if (! $markPrice || ! is_numeric($markPrice)) {
            throw new RuntimeException("Failed to fetch mark price for {$exchangeSymbol->parsed_trading_pair}");
        }

        $exchangeSymbol->storeMarkPriceSnapshot($markPrice);

        // Calculate profit order data (re-anchor TP if mark price already passed it)
        $profitData = Kraite::calculateProfitOrder(
            direction: $direction,
            referencePrice: $this->position->opening_price,
            profitPercent: $this->position->profit_percentage,
            currentQty: $this->position->quantity,
            exchangeSymbol: $exchangeSymbol,
            recalculateOnLowerThanMarkPrice: true,
        );

        // Create Order record
        $this->profitOrder = Order::createForPosition([
            'position_id' => $this->position->id,
            'type' => 'PROFIT-LIMIT',
            'status' => 'NEW',
            'side' => $side,
            'position_side' => $direction,
            'price' => $profitData['price'],
            'quantity' => $profitData['quantity'],
        ]);

        // Place on exchange
        $this->profitOrder->apiPlace();

        return [
            'position_id' => $this->position->id,
            'order_id' => $this->profitOrder->id,
            'trading_pair' => $exchangeSymbol->parsed_trading_pair,
            'direction' => $direction,
            'side' => $side,
            'price' => $profitData['price'],
            'quantity' => $profitData['quantity'],
            'reference_price' => $this->position->opening_price,
            'profit_percentage' => $this->position->profit_percentage,
            'message' => 'Profit order placed on exchange',
        ];
    }

    /**
     * Verify the profit order was accepted.
     */
    public function doubleCheck(): bool
    {
        if ($this->profitOrder === null) {
            return false;
        }

        $this->profitOrder->apiSync();
        $this->profitOrder->refresh();

        // Profit order is accepted if status is NEW (waiting) or FILLED (price hit immediately)
        return in_array($this->profitOrder->status, ['NEW', 'PARTIALLY_FILLED', 'FILLED'], true);
    }

    /**
     * Set reference data from first sync.
     */
    public function complete(): void
    {
        if ($this->profitOrder === null) {
            return;
        }

        $this->profitOrder->updateSaving([
            'reference_price' => $this->profitOrder->price,
            'reference_quantity' => $this->profitOrder->quantity,
            'reference_status' => $this->profitOrder->status,
        ]);

        // Store first_profit_price on position for reference
        $this->position->updateSaving([
            'first_profit_price' => $this->profitOrder->price,
        ]);

        $this->position->appLog(
            event: 'profit_order_placed',
            message: "Profit order placed at \${$this->profitOrder->price}",
            metadata: [
                'order_id' => $this->profitOrder->id,
                'price' => $this->profitOrder->price,
                'quantity' => $this->profitOrder->quantity,
            ]
        );
    }

    /**
     * Handle exceptions during profit order placement.
     */
    public function resolveException(Throwable $e): void
    {
        $this->position->updateSaving([
            'error_message' => 'Profit order failed: '.$e->getMessage(),
        ]);
    }
}
