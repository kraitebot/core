<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\Order;

use Illuminate\Support\Facades\Log;
use Kraite\Core\Abstracts\BaseApiableJob;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Models\Position;
use RuntimeException;
use Throwable;

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
final class SyncPositionOrdersJob extends BaseApiableJob
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
        // Position must be in an "opened" status
        if (! in_array($this->position->status, $this->position->openedStatuses(), true)) {
            return false;
        }

        // Must have at least one syncable order
        return $this->position->orders()->syncable()->exists();
    }

    public function computeApiable()
    {
        // The flip to 'syncing' lives inside the atomic (not in the parent
        // orchestrator) so that the flip and the flip-back share a single
        // owner. If startOrFail rejected us upstream, this line never runs
        // and no transition needs unwinding. The framework's complete()
        // hook (see below) owns the flip-back on success; retry / ignore /
        // fail paths are handled by the framework's handleException chain
        // and intentionally leave the position in 'syncing' so a wedged
        // sync is visible instead of silently rolled back.
        $this->position->refresh();
        if ($this->position->status === 'active') {
            $this->position->updateToSyncing();
        }

        $syncedOrders = [];
        $failedOrders = [];

        // Get all syncable orders (non-MARKET with exchange_order_id)
        $orders = $this->position->orders()->syncable()->get();

        foreach ($orders as $order) {
            try {
                $order->apiSync();
                $syncedOrders[] = [
                    'id' => $order->id,
                    'type' => $order->type,
                    'status' => $order->status,
                ];
            } catch (Throwable $e) {
                Log::channel('jobs')->error('[SYNC] order sync failed', [
                    'order_id' => $order->id,
                    'position_id' => $this->position->id,
                    'error' => $e->getMessage(),
                ]);
                $failedOrders[] = [
                    'id' => $order->id,
                    'type' => $order->type,
                    'error' => $e->getMessage(),
                ];
            }
        }

        // If every order failed, surface the failure so the job-level retry
        // kicks in. Partial failures still pass — individual stuck orders
        // get picked up by the next sync tick. A total failure almost always
        // signals a broader issue (rate limit, exchange outage, auth) that
        // deserves explicit retry/alerting instead of silent skip.
        if (count($orders) > 0 && count($syncedOrders) === 0 && count($failedOrders) > 0) {
            $firstError = $failedOrders[0]['error'] ?? 'unknown error';
            $failureCount = count($failedOrders);
            throw new RuntimeException(
                "All {$failureCount} orders failed to sync for position {$this->position->id}: {$firstError}"
            );
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

    /**
     * Success-path flip-back.
     *
     * The framework invokes complete() only after compute() returns cleanly
     * (see HandlesStepLifecycle::shouldComplete). Exception paths
     * (retry / ignore / resolve / fail) are routed by handleException and do
     * not call complete(), so a wedged sync intentionally leaves the
     * position in 'syncing'. The `=== 'syncing'` gate guards against an
     * observer-dispatched workflow (Close / Wap / Replace) that claimed the
     * position mid-compute — we must not overwrite 'closing' / 'waping'.
     */
    public function complete(): void
    {
        $this->position->refresh();

        if ($this->position->status === 'syncing') {
            $this->position->updateToActive();
        }
    }
}
