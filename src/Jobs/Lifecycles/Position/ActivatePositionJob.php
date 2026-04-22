<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Lifecycles\Position;

use Kraite\Core\Abstracts\BasePositionLifecycle;
use Kraite\Core\Jobs\Atomic\Position\ActivatePositionJob as AtomicActivatePositionJob;
use StepDispatcher\Models\Step;

/**
 * ActivatePositionJob (Lifecycle)
 *
 * Orchestrator that creates step for activating a position.
 * This is the final step in the position opening workflow.
 *
 * Flow:
 * - Step N: ActivatePositionJob (Atomic) - Validates orders, sets status='active'
 *
 * Must run AFTER all orders are placed (market, limit, TP, SL).
 */
final class ActivatePositionJob extends BasePositionLifecycle
{
    public function dispatch(string $blockUuid, int $startIndex, ?string $workflowId = null): int
    {
        Step::create([
            'class' => $this->resolver->resolve(AtomicActivatePositionJob::class),
            'queue' => 'positions',
            'arguments' => ['positionId' => $this->position->id],
            'block_uuid' => $blockUuid,
            'index' => $startIndex,
            'workflow_id' => $workflowId,
        ]);

        return $startIndex + 1;
    }
}
