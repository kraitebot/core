<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\Order;

use Illuminate\Support\Facades\Log;
use Kraite\Core\Abstracts\BaseApiableJob;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Models\Position;

/**
 * SyncPositionOrdersJob
 *
 * Syncs all syncable orders for a given position.
 * Syncable orders = non-MARKET orders with an exchange_order_id.
 *
 * This job calls apiSync() on each order, which updates:
 * - status
 * - quantity
 * - price
 *
 * The Order Observer detects changes and triggers appropriate workflows
 * (e.g., ClosePositionJob when profit/stop order is FILLED).
 */
class SyncPositionOrdersJob extends BaseApiableJob
{
    public Position $position;

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
     * Verify the position can have orders synced.
     */
    public function startOrFail(): bool
    {
        Log::channel('jobs')->info('[SYNC-DEBUG] startOrFail() called', [
            'position_id' => $this->position->id,
            'status' => $this->position->status,
            'opened_statuses' => $this->position->openedStatuses(),
        ]);

        // Position must be in an "opened" status
        if (! in_array($this->position->status, $this->position->openedStatuses(), true)) {
            Log::channel('jobs')->info('[SYNC-DEBUG] startOrFail() → false (status not in openedStatuses)');

            return false;
        }

        $hasSyncable = $this->position->orders()->syncable()->exists();
        Log::channel('jobs')->info('[SYNC-DEBUG] startOrFail() → syncable exists: '.($hasSyncable ? 'yes' : 'no'));

        // Must have at least one syncable order
        return $hasSyncable;
    }

    public function computeApiable()
    {
        Log::channel('jobs')->info('[SYNC-DEBUG] computeApiable() START', [
            'position_id' => $this->position->id,
        ]);

        $syncedOrders = [];
        $failedOrders = [];

        // Get all syncable orders (non-MARKET with exchange_order_id)
        $orders = $this->position->orders()->syncable()->get();
        Log::channel('jobs')->info('[SYNC-DEBUG] Found '.count($orders).' syncable orders');

        foreach ($orders as $order) {
            Log::channel('jobs')->info('[SYNC-DEBUG] Syncing order', [
                'order_id' => $order->id,
                'type' => $order->type,
                'status_before' => $order->status,
            ]);

            try {
                $order->apiSync();
                Log::channel('jobs')->info('[SYNC-DEBUG] Order synced OK', [
                    'order_id' => $order->id,
                    'status_after' => $order->status,
                ]);
                $syncedOrders[] = [
                    'id' => $order->id,
                    'type' => $order->type,
                    'status' => $order->status,
                ];
            } catch (\Throwable $e) {
                Log::channel('jobs')->error('[SYNC-DEBUG] Order sync FAILED', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
                $failedOrders[] = [
                    'id' => $order->id,
                    'type' => $order->type,
                    'error' => $e->getMessage(),
                ];
            }
        }

        Log::channel('jobs')->info('[SYNC-DEBUG] computeApiable() END', [
            'synced' => count($syncedOrders),
            'failed' => count($failedOrders),
        ]);

        // Set position back to 'active' after syncing completes.
        // If an observer dispatched another workflow (close/cancel/replace),
        // that workflow's first step will override this status.
        $this->position->refresh();
        if ($this->position->status === 'syncing') {
            $this->position->updateToActive();
        }

        return [
            'position_id' => $this->position->id,
            'synced_count' => count($syncedOrders),
            'failed_count' => count($failedOrders),
            'synced_orders' => $syncedOrders,
            'failed_orders' => $failedOrders,
            'message' => 'Position orders synced',
        ];
    }
}
