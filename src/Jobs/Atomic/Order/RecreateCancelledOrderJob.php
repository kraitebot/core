<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\Order;

use Kraite\Core\Abstracts\BaseApiableJob;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\Math;
use Throwable;

/**
 * RecreateCancelledOrderJob (Atomic)
 *
 * Recreates a single cancelled/expired order with smart quantity calculation.
 *
 * Logic:
 * - Price: same as original order (reference_price or price)
 * - Quantity: reference_quantity - filled_quantity (remaining unfilled amount)
 *
 * Flow:
 * 1. startOrFail(): Verify position is active, order needs recreation
 * 2. computeApiable(): Create new Order record, place on exchange
 * 3. doubleCheck(): Verify order was accepted
 * 4. complete(): Set reference_* fields, mark old order as handled
 */
final class RecreateCancelledOrderJob extends BaseApiableJob
{
    public Position $position;

    public Order $cancelledOrder;

    public ?Order $newOrder = null;

    public function __construct(int $positionId, int $orderId)
    {
        $this->position = Position::findOrFail($positionId);
        $this->cancelledOrder = Order::findOrFail($orderId);
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
     * Verify order needs recreation and position is ready.
     *
     * On retry, restore $this->newOrder from DB if a prior attempt already
     * created the replacement (linked via `recreated_from_order_id`).
     * Without this, a worker death between `apiPlace()` succeeding and
     * `doubleCheck()` completing would let the framework retry
     * `computeApiable()` against a fresh `$this->newOrder` (null on
     * reconstruction), writing a second local Order row and placing a
     * duplicate exchange order. Same idempotency shape as
     * `PlaceMarketOrderJob` and `PlaceLimitOrderJob`.
     */
    public function startOrFail(): bool
    {
        // Position must be in an active status
        if (! in_array($this->position->status, $this->position->activeStatuses(), true)) {
            return false;
        }

        // Order must be cancelled or expired
        if (! in_array($this->cancelledOrder->status, ['CANCELLED', 'EXPIRED'], true)) {
            return false;
        }

        // Order must belong to this position
        if ($this->cancelledOrder->position_id !== $this->position->id) {
            return false;
        }

        // Order must have a price (LIMIT, PROFIT-LIMIT, STOP-MARKET)
        if ($this->cancelledOrder->price === null) {
            return false;
        }

        // Remaining-quantity gate protects LIMIT-style orders where a
        // fully-consumed position has nothing left to recreate. It must
        // NOT fire for closePosition-style algo orders (STOP-MARKET on
        // Binance with closePosition=true and peers on other exchanges)
        // where reference_quantity=0 is the canonical valid value — the
        // order closes whatever is open at trigger time, quantity is
        // irrelevant at placement.
        if (! $this->isCloseAllAlgoOrder()) {
            $remainingQty = $this->calculateRemainingQuantity();
            if (Math::lte($remainingQty, '0')) {
                return false;
            }
        }

        // Idempotent-resume: surface a prior replacement so computeApiable
        // can short-circuit `Order::create` + `apiPlace()` whenever the
        // exchange already accepted the order on a prior attempt.
        $existingReplacement = Order::query()
            ->where('recreated_from_order_id', $this->cancelledOrder->id)
            ->latest('id')
            ->first();

        if ($existingReplacement !== null) {
            $this->newOrder = $existingReplacement;
        }

        return true;
    }

    public function computeApiable()
    {
        // Retry path: a prior attempt already placed the replacement order on
        // the exchange. Skip Order::create + apiPlace; doubleCheck() +
        // complete() will run against the existing row and finalise normally.
        if ($this->newOrder !== null && $this->newOrder->exchange_order_id !== null) {
            return [
                'position_id' => $this->position->id,
                'cancelled_order_id' => $this->cancelledOrder->id,
                'new_order_id' => $this->newOrder->id,
                'type' => $this->newOrder->type,
                'price' => $this->newOrder->price,
                'quantity' => $this->newOrder->quantity,
                'exchange_order_id' => $this->newOrder->exchange_order_id,
                'message' => 'Replacement order already placed on prior attempt — skipping re-placement',
            ];
        }

        $direction = $this->position->direction;

        // Side is same as original order
        $side = $this->cancelledOrder->side;

        // Price from original order (prefer reference_price if set)
        $price = $this->cancelledOrder->reference_price ?? $this->cancelledOrder->price;

        // Calculate remaining quantity
        $quantity = $this->calculateRemainingQuantity();

        // Reuse any Order row a prior attempt left behind without an
        // exchange_order_id (the apiPlace never reached the exchange) so
        // we don't accumulate orphan rows on retries.
        if ($this->newOrder === null) {
            $this->newOrder = Order::create([
                'position_id' => $this->position->id,
                'type' => $this->cancelledOrder->type,
                'status' => 'NEW',
                'side' => $side,
                'position_side' => $direction,
                'price' => $price,
                'quantity' => $quantity,
                'is_algo' => $this->cancelledOrder->is_algo,
                'recreated_from_order_id' => $this->cancelledOrder->id,
            ]);
        }

        // Place on exchange
        $this->newOrder->apiPlace();

        return [
            'position_id' => $this->position->id,
            'cancelled_order_id' => $this->cancelledOrder->id,
            'new_order_id' => $this->newOrder->id,
            'type' => $this->cancelledOrder->type,
            'price' => $price,
            'quantity' => $quantity,
            'message' => 'Order recreated successfully',
        ];
    }

    /**
     * Verify the new order was accepted.
     */
    public function doubleCheck(): bool
    {
        if ($this->newOrder === null) {
            return false;
        }

        $this->newOrder->apiSync();
        $this->newOrder->refresh();

        // Order is accepted if status is NEW (waiting) or FILLED (triggered immediately)
        return in_array($this->newOrder->status, ['NEW', 'PARTIALLY_FILLED', 'FILLED'], true);
    }

    /**
     * Set reference data and mark old order as handled.
     */
    public function complete(): void
    {
        // Set reference data on new order
        if ($this->newOrder !== null) {
            $this->newOrder->updateSaving([
                'reference_price' => $this->newOrder->price,
                'reference_quantity' => $this->newOrder->quantity,
                'reference_status' => $this->newOrder->status,
            ]);
        }

        // Update old order's reference_status to match its status
        // This prevents OrderObserver from triggering again
        $this->cancelledOrder->updateSaving([
            'reference_status' => $this->cancelledOrder->status,
        ]);
    }

    /**
     * Calculate remaining quantity to recreate.
     *
     * If order was partially filled, only recreate the unfilled portion.
     */
    public function calculateRemainingQuantity(): string
    {
        $referenceQty = $this->cancelledOrder->reference_quantity
            ?? $this->cancelledOrder->quantity;

        $filledQty = $this->cancelledOrder->filled_quantity ?? '0';

        return Math::sub($referenceQty, $filledQty);
    }

    /**
     * Handle exceptions during order recreation.
     */
    public function resolveException(Throwable $e): void
    {
        $this->position->updateSaving([
            'error_message' => 'Order recreation failed: '.$e->getMessage(),
        ]);
    }

    /**
     * Detect closePosition-style algo orders where quantity=0 is the
     * canonical valid placement value (Binance STOP-MARKET with
     * closePosition=true, and equivalent patterns on Bitget / Kucoin /
     * Bybit). For these, the remaining-quantity gate in startOrFail
     * must not apply.
     */
    private function isCloseAllAlgoOrder(): bool
    {
        if (! $this->cancelledOrder->is_algo) {
            return false;
        }

        $referenceQty = (string) ($this->cancelledOrder->reference_quantity
            ?? $this->cancelledOrder->quantity
            ?? '0');

        return Math::equal($referenceQty, '0');
    }
}
