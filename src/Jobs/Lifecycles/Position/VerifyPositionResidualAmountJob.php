<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Lifecycles\Position;

use Kraite\Core\Abstracts\BasePositionLifecycle;
use Kraite\Core\Jobs\Atomic\Position\VerifyPositionResidualAmountJob as AtomicVerifyPositionResidualAmountJob;
use StepDispatcher\Models\Step;

/**
 * VerifyPositionResidualAmountJob (Lifecycle)
 *
 * Orchestrator that creates step for verifying no residual position remains.
 * Checks the account-positions snapshot after closing.
 */
class VerifyPositionResidualAmountJob extends BasePositionLifecycle
{
    public function dispatch(string $blockUuid, int $startIndex, ?string $workflowId = null): int
    {
        Step::create([
            'class' => $this->resolver->resolve(AtomicVerifyPositionResidualAmountJob::class),
            'arguments' => ['positionId' => $this->position->id],
            'block_uuid' => $blockUuid,
            'index' => $startIndex,
            'workflow_id' => $workflowId,
        ]);

        return $startIndex + 1;
    }
}
