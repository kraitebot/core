<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\Order;

use Kraite\Core\Abstracts\BaseApiableJob;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Throwable;

/**
 * CancelSingleAlgoOrderJob (Atomic)
 *
 * Cancels a single algo order (STOP-MARKET, etc.) on the exchange.
 * Used when correcting a modified algo order (cancel + recreate workflow).
 *
 * This job:
 * 1. Verifies the order is an active algo order
 * 2. Calls apiCancel() which routes to exchange-specific cancel endpoint
 * 3. Updates local status to CANCELLED
 *
 * Note: reference_status should be pre-set to 'CANCELLED' by the calling job
 * to prevent OrderObserver from triggering replacement workflows.
 */
class CancelSingleAlgoOrderJob extends BaseApiableJob
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
     * Verify order can be cancelled.
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

        // Order must be an algo order
        if (! $this->order->is_algo) {
            return false;
        }

        // Order must be active (NEW or PARTIALLY_FILLED)
        if (! in_array($this->order->status, ['NEW', 'PARTIALLY_FILLED'], true)) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function computeApiable(): array
    {
        // Cancel the algo order via exchange-specific endpoint
        $apiResponse = $this->order->apiCancel();

        // Update local status
        $this->order->updateSaving(['status' => 'CANCELLED']);

        return [
            'position_id' => $this->position->id,
            'order_id' => $this->order->id,
            'type' => $this->order->type,
            'exchange_order_id' => $this->order->exchange_order_id,
            'api_result' => $apiResponse->result,
            'message' => 'Algo order cancelled',
        ];
    }

    /**
     * Verify the order was cancelled.
     *
     * For BitGet position-level TPSL (pos_profit/pos_loss), cancel-plan-order
     * doesn't work because they're attached to the position. In this case,
     * we skip verification since the order will remain on exchange until
     * the position closes.
     */
    public function doubleCheck(): bool
    {
        // Sync order to get current status from exchange
        $apiResponse = $this->order->apiSync();
        $this->order->refresh();

        // BitGet position-level TPSL cannot be cancelled via cancel-plan-order.
        // Detect this by checking the _isPositionTpsl flag in the sync response.
        // If so, revert local status since the order is still active on exchange.
        if (($apiResponse->result['_isPositionTpsl'] ?? false) && $this->order->status === 'NEW') {
            // Order is still active - this is expected for position TPSL
            // Revert reference_status to match actual status to prevent further drift
            $this->order->updateSaving([
                'reference_status' => $this->order->status,
                'reference_quantity' => $this->order->quantity,
            ]);

            // Return true to complete the job - the "cancel" is acknowledged as not applicable
            return true;
        }

        // Order should be CANCELLED
        return $this->order->status === 'CANCELLED';
    }

    /**
     * Handle exceptions during cancel.
     */
    public function resolveException(Throwable $e): void
    {
        $this->position->updateSaving([
            'error_message' => 'Algo order cancel failed: ' . $e->getMessage(),
        ]);
    }
}
