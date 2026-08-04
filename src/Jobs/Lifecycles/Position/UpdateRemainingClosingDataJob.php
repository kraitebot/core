<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Lifecycles\Position;

use Kraite\Core\Abstracts\BasePositionLifecycle;
use Kraite\Core\Jobs\Atomic\Position\UpdateRemainingClosingDataJob as AtomicUpdateRemainingClosingDataJob;
use StepDispatcher\Models\Step;

/**
 * UpdateRemainingClosingDataJob (Lifecycle)
 *
 * Orchestrator that creates step for updating closing data.
 * Sets closing_price and was_fast_traded before the position is finalized.
 */
final class UpdateRemainingClosingDataJob extends BasePositionLifecycle
{
    public function dispatch(string $blockUuid, int $startIndex, ?string $workflowId = null): int
    {
        Step::create([
            'class' => $this->resolver->resolve(AtomicUpdateRemainingClosingDataJob::class),
            'queue' => 'positions',
            'arguments' => ['positionId' => $this->position->id],
            'block_uuid' => $blockUuid,
            'index' => $startIndex,
            'workflow_id' => $workflowId,
        ]);

        return $startIndex + 1;
    }
}
