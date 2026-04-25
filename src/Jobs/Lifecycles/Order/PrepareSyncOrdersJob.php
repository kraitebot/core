<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Lifecycles\Order;

use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Jobs\Lifecycles\Order\SyncPositionOrdersJob as SyncPositionOrdersLifecycle;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\Proxies\JobProxy;

/**
 * PrepareSyncOrdersJob (Orchestrator)
 *
 * Top-level lifecycle orchestrator dispatched by kraite:cron-sync-orders.
 * Creates the atomic SyncPositionOrdersJob as a child step.
 *
 * The atomic sync job updates order statuses from the exchange.
 * The Order Observer detects changes and dispatches independent
 * lifecycle steps (CancelPositionJob / ClosePositionJob) as needed.
 */
final class PrepareSyncOrdersJob extends BaseQueueableJob
{
    public Position $position;

    public function __construct(int $positionId)
    {
        $this->position = Position::findOrFail($positionId);
    }

    public function relatable()
    {
        return $this->position;
    }

    public function compute()
    {
        // Only dispatch the atomic sync when the position is in steady-state
        // 'active'. Other opened statuses (opening, syncing, closing,
        // cancelling, waping) belong to their respective workflows — firing a
        // sync on top of them races with in-flight dispatches. Crucially, this
        // job no longer flips the position to 'syncing'; that transition and
        // its matching flip-back are both owned by SyncPositionOrdersJob
        // (via the framework's complete() hook), so a crash between here
        // and the child step can no longer leave a position wedged in
        // 'syncing'.
        $this->position->refresh();

        if ($this->position->status !== 'active') {
            return [
                'position_id' => $this->position->id,
                'status' => $this->position->status,
                'message' => 'Skipped — position not in active state, another workflow owns it',
            ];
        }

        $resolver = JobProxy::with($this->position->account);
        $lifecycleClass = $resolver->resolve(SyncPositionOrdersLifecycle::class);
        $lifecycle = new $lifecycleClass($this->position);

        // Self-elect to parent mode now that we've decided to spawn a child.
        // The early-return path above (position not 'active') skips this and
        // leaves child_block_uuid null, so the framework lets the step
        // Complete normally as an orphan instead of waiting forever for
        // children that never come.
        $childBlockUuid = $this->step->makeItAParent();

        $lifecycle->dispatch(
            blockUuid: $childBlockUuid,
            startIndex: 1,
            workflowId: null
        );

        return [
            'position_id' => $this->position->id,
            'message' => 'Sync orders workflow initiated',
        ];
    }
}
