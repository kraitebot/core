<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Lifecycles\Order;

use Kraite\Core\Abstracts\BasePositionLifecycle;
use Kraite\Core\Jobs\Atomic\Order\SyncPositionOrdersJob as AtomicSyncPositionOrdersJob;
use StepDispatcher\Models\Step;

/**
 * SyncPositionOrdersJob (Lifecycle)
 *
 * Orchestrator that creates step for syncing all position orders from exchange.
 * Updates order status, quantity, and price from the exchange.
 */
class SyncPositionOrdersJob extends BasePositionLifecycle
{
    public function dispatch(string $blockUuid, int $startIndex, ?string $workflowId = null): int
    {
        Step::create([
            'class' => $this->resolver->resolve(AtomicSyncPositionOrdersJob::class),
            'arguments' => ['positionId' => $this->position->id],
            'block_uuid' => $blockUuid,
            'index' => $startIndex,
            'workflow_id' => $workflowId,
        ]);

        return $startIndex + 1;
    }
}
