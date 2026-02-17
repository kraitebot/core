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
class RecreateCancelledOrderJob extends BaseApiableJob
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

        // Calculate remaining quantity - must be positive
        $remainingQty = $this->calculateRemainingQuantity();
        if (Math::lte($remainingQty, '0')) {
            return false;
        }

        return true;
    }

    public function computeApiable()
    {
        $direction = $this->position->direction;

        // Side is same as original order
        $side = $this->cancelledOrder->side;

        // Price from original order (prefer reference_price if set)
        $price = $this->cancelledOrder->reference_price ?? $this->cancelledOrder->price;

        // Calculate remaining quantity
        $quantity = $this->calculateRemainingQuantity();

        // Create new Order record
        $this->newOrder = Order::create([
            'position_id' => $this->position->id,
            'type' => $this->cancelledOrder->type,
            'status' => 'NEW',
            'side' => $side,
            'position_side' => $direction,
            'price' => $price,
            'quantity' => $quantity,
            'is_algo' => $this->cancelledOrder->is_algo,
        ]);

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
            'error_message' => 'Order recreation failed: ' . $e->getMessage(),
        ]);
    }
}
