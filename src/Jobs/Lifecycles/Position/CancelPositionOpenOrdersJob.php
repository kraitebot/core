<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Lifecycles\Position;

use Kraite\Core\Abstracts\BasePositionLifecycle;
use Kraite\Core\Jobs\Atomic\Position\CancelAlgoOpenOrdersJob as AtomicCancelAlgoOpenOrdersJob;
use Kraite\Core\Jobs\Atomic\Position\CancelPositionOpenOrdersJob as AtomicCancelPositionOpenOrdersJob;
use StepDispatcher\Models\Step;

/**
 * CancelPositionOpenOrdersJob (Lifecycle)
 *
 * Orchestrator that creates steps for cancelling all open orders for a position.
 * Step 1: Bulk cancel regular orders via exchange's cancel-all endpoint.
 * Step 2: Cancel algo orders individually (stop-loss, etc.) via exchange-specific endpoints.
 */
class CancelPositionOpenOrdersJob extends BasePositionLifecycle
{
    public function dispatch(string $blockUuid, int $startIndex, ?string $workflowId = null): int
    {
        // Step 1: Bulk cancel regular orders
        Step::create([
            'class' => $this->resolver->resolve(AtomicCancelPositionOpenOrdersJob::class),
            'arguments' => ['positionId' => $this->position->id],
            'block_uuid' => $blockUuid,
            'index' => $startIndex,
            'workflow_id' => $workflowId,
        ]);

        // Step 2: Cancel algo orders (stop-loss, etc.) via exchange-specific endpoints
        Step::create([
            'class' => $this->resolver->resolve(AtomicCancelAlgoOpenOrdersJob::class),
            'arguments' => ['positionId' => $this->position->id],
            'block_uuid' => $blockUuid,
            'index' => $startIndex + 1,
            'workflow_id' => $workflowId,
        ]);

        return $startIndex + 2;
    }
}
