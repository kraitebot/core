<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Lifecycles\Order;

use Illuminate\Support\Facades\Log;
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
        // Temporary instrumentation: profile each block to pinpoint the 1-5s
        // baseline for what should be a sub-100ms orchestrator. Remove once
        // bottleneck is identified.
        $timings = [];
        $t0 = microtime(true);

        // Only flip to 'syncing' when the position is in steady-state 'active'.
        // Other opened statuses (opening, syncing, closing, cancelling) are
        // owned by their respective workflows — clobbering them here races
        // with in-flight dispatches (e.g. ActivatePositionJob still placing
        // SL/TP) and can promote a half-opened position to 'active'
        // prematurely via SyncPositionOrdersJob's end-of-job flip.
        $this->position->refresh();
        $timings['refresh_ms'] = (int) round((microtime(true) - $t0) * 1000);

        if ($this->position->status !== 'active') {
            $timings['total_ms'] = (int) round((microtime(true) - $t0) * 1000);
            Log::channel('jobs')->info('[PREPARE-SYNC-PROFILE] skipped', [
                'step_id' => $this->step->id,
                'position_id' => $this->position->id,
                'status' => $this->position->status,
                'timings' => $timings,
            ]);

            return [
                'position_id' => $this->position->id,
                'status' => $this->position->status,
                'message' => 'Skipped — position not in active state, another workflow owns it',
            ];
        }

        $t1 = microtime(true);
        $this->position->updateToSyncing();
        $timings['update_to_syncing_ms'] = (int) round((microtime(true) - $t1) * 1000);

        $t2 = microtime(true);
        $resolver = JobProxy::with($this->position->account);
        $lifecycleClass = $resolver->resolve(SyncPositionOrdersLifecycle::class);
        $lifecycle = new $lifecycleClass($this->position);
        $timings['resolve_lifecycle_ms'] = (int) round((microtime(true) - $t2) * 1000);

        $t3 = microtime(true);
        $lifecycle->dispatch(
            blockUuid: $this->uuid(),
            startIndex: 1,
            workflowId: null
        );
        $timings['dispatch_child_ms'] = (int) round((microtime(true) - $t3) * 1000);

        $timings['total_ms'] = (int) round((microtime(true) - $t0) * 1000);

        Log::channel('jobs')->info('[PREPARE-SYNC-PROFILE] completed', [
            'step_id' => $this->step->id,
            'position_id' => $this->position->id,
            'timings' => $timings,
        ]);

        return [
            'position_id' => $this->position->id,
            'message' => 'Sync orders workflow initiated',
            'timings' => $timings,
        ];
    }
}
