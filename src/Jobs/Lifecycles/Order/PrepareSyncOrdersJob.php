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
        // Only flip to 'syncing' when the position is in steady-state 'active'.
        // Other opened statuses (opening, syncing, closing, cancelling) are
        // owned by their respective workflows — clobbering them here races
        // with in-flight dispatches (e.g. ActivatePositionJob still placing
        // SL/TP) and can promote a half-opened position to 'active'
        // prematurely via SyncPositionOrdersJob's end-of-job flip.
        $this->position->refresh();

        if ($this->position->status !== 'active') {
            return [
                'position_id' => $this->position->id,
                'status' => $this->position->status,
                'message' => 'Skipped — position not in active state, another workflow owns it',
            ];
        }

        $this->position->updateToSyncing();

        $resolver = JobProxy::with($this->position->account);

        $lifecycleClass = $resolver->resolve(SyncPositionOrdersLifecycle::class);
        $lifecycle = new $lifecycleClass($this->position);
        $lifecycle->dispatch(
            blockUuid: $this->uuid(),
            startIndex: 1,
            workflowId: null
        );

        return [
            'position_id' => $this->position->id,
            'message' => 'Sync orders workflow initiated',
        ];
    }
}
