<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Lifecycles\Position;

use Kraite\Core\Abstracts\BasePositionLifecycle;
use Kraite\Core\Jobs\Atomic\Position\DetermineLeverageJob as AtomicDetermineLeverageJob;
use StepDispatcher\Models\Step;

/**
 * DetermineLeverageJob (Lifecycle)
 *
 * Orchestrator that creates step(s) for determining optimal leverage.
 * Default implementation creates a single atomic step.
 *
 * Flow:
 * - Step N: DetermineLeverageJob (Atomic) - Determines leverage based on margin and brackets
 *
 * Must run AFTER PreparePositionDataJob (margin must be set).
 * Must run BEFORE SetLeverageJob.
 */
final class DetermineLeverageJob extends BasePositionLifecycle
{
    public function dispatch(string $blockUuid, int $startIndex, ?string $workflowId = null): int
    {
        Step::create([
            'class' => $this->resolver->resolve(AtomicDetermineLeverageJob::class),
            'queue' => 'positions',
            'arguments' => ['positionId' => $this->position->id],
            'block_uuid' => $blockUuid,
            'index' => $startIndex,
            'workflow_id' => $workflowId,
        ]);

        return $startIndex + 1;
    }
}
