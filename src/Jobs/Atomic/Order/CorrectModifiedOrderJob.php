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
 * CorrectModifiedOrderJob (Atomic)
 *
 * Corrects a LIMIT order that was modified on the exchange by restoring
 * its original reference values using apiModify().
 *
 * This job handles NON-ALGO orders only. Algo orders (STOP-MARKET, etc.)
 * require cancel+recreate because exchanges don't support algo order modification.
 *
 * Flow:
 * 1. startOrFail(): Verify order was modified and needs correction
 * 2. computeApiable(): Call apiModify() with reference values
 * 3. doubleCheck(): Sync order and verify values were restored
 * 4. complete(): Update reference_* fields to match corrected values
 */
class CorrectModifiedOrderJob extends BaseApiableJob
{
    public Position $position;

    public Order $order;

    public function __construct(int $positionId, int $orderId)
    {
        $this->position = Position::findOrFail($positionId);
        $this->order = Order::findOrFail($orderId);
    }

    public function assignExceptionHandler(): void
    {
        $this->exceptionHandler = BaseExceptionHandler::make(
            $this->position->account->apiSystem->canonical
        )->withAccount($this->position->account);
    }

    public function relatable(): Position
    {
        return $this->position;
    }

    /**
     * Verify order needs correction and is correctable.
     */
    public function startOrFail(): bool
    {
        // Position must be in an active status
        if (! in_array($this->position->status, $this->position->activeStatuses(), true)) {
            return false;
        }

        // Order must belong to this position
        if ($this->order->position_id !== $this->position->id) {
            return false;
        }

        // Order must be active (NEW or PARTIALLY_FILLED)
        if (! in_array($this->order->status, ['NEW', 'PARTIALLY_FILLED'], true)) {
            return false;
        }

        // Order must NOT be algo (algo orders require cancel+recreate)
        if ($this->order->is_algo) {
            return false;
        }

        // Must have reference values to restore
        if ($this->order->reference_price === null && $this->order->reference_quantity === null) {
            return false;
        }

        // Match the OrderObserver's drift comparison (Math::equal).
        // The Order::price accessor strips trailing zeros while
        // reference_price returns the raw DECIMAL(20,8) string, so a
        // strict `!==` produces false-drift on numerically equal pairs
        // ('1.4769' vs '1.47690000').
        $hasPriceDrift = $this->order->reference_price !== null
            && $this->order->price !== null
            && ! Math::equal($this->order->price, $this->order->reference_price);

        $hasQuantityDrift = $this->order->reference_quantity !== null
            && $this->order->quantity !== null
            && ! Math::equal($this->order->quantity, $this->order->reference_quantity);

        if (! $hasPriceDrift && ! $hasQuantityDrift) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function computeApiable(): array
    {
        // Get reference values to restore
        $referenceQuantity = $this->order->reference_quantity ?? $this->order->quantity;
        $referencePrice = $this->order->reference_price ?? $this->order->price;

        // Call apiModify with reference values
        $apiResponse = $this->order->apiModify(
            quantity: (float) $referenceQuantity,
            price: (float) $referencePrice
        );

        return [
            'position_id' => $this->position->id,
            'order_id' => $this->order->id,
            'type' => $this->order->type,
            'original_price' => $this->order->price,
            'original_quantity' => $this->order->quantity,
            'restored_price' => $referencePrice,
            'restored_quantity' => $referenceQuantity,
            'api_result' => $apiResponse->result,
            'message' => 'Order modification corrected',
        ];
    }

    /**
     * Verify the order was corrected.
     */
    public function doubleCheck(): bool
    {
        // Sync order to get current values from exchange
        $this->order->apiSync();
        $this->order->refresh();

        // Order must still be active
        if (! in_array($this->order->status, ['NEW', 'PARTIALLY_FILLED'], true)) {
            return false;
        }

        // Price should match reference (precision-aware — accessor-stripped
        // values like '0.10' must compare equal to raw '0.10000000').
        if ($this->order->reference_price !== null && $this->order->price !== null) {
            if (! Math::equal($this->order->price, $this->order->reference_price)) {
                return false;
            }
        }

        // Quantity should match reference (same precision-aware rationale).
        if ($this->order->reference_quantity !== null && $this->order->quantity !== null) {
            if (! Math::equal($this->order->quantity, $this->order->reference_quantity)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Update reference fields to prevent re-triggering.
     */
    public function complete(): void
    {
        // Update reference_* to match current values
        // This ensures the OrderObserver won't detect drift again
        $this->order->updateSaving([
            'reference_price' => $this->order->price,
            'reference_quantity' => $this->order->quantity,
        ]);
    }

    /**
     * Handle exceptions during order correction.
     */
    public function resolveException(Throwable $e): void
    {
        $this->position->updateSaving([
            'error_message' => 'Order correction failed: ' . $e->getMessage(),
        ]);
    }
}
