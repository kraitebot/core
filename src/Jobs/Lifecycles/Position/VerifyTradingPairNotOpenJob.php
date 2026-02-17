<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Lifecycles\Position;

use Kraite\Core\Abstracts\BasePositionLifecycle;
use Kraite\Core\Jobs\Atomic\Position\VerifyTradingPairNotOpenJob as AtomicVerifyTradingPairNotOpenJob;
use StepDispatcher\Models\Step;

/**
 * VerifyTradingPairNotOpenJob (Lifecycle)
 *
 * Orchestrator that creates step(s) for verifying a trading pair is not already open.
 * Default implementation creates a single atomic step.
 * Exchange-specific overrides can add additional checks if needed.
 */
class VerifyTradingPairNotOpenJob extends BasePositionLifecycle
{
    public function dispatch(string $blockUuid, int $startIndex, ?string $workflowId = null): int
    {
        Step::create([
            'class' => $this->resolver->resolve(AtomicVerifyTradingPairNotOpenJob::class),
            'arguments' => ['positionId' => $this->position->id],
            'block_uuid' => $blockUuid,
            'index' => $startIndex,
            'workflow_id' => $workflowId,
        ]);

        return $startIndex + 1;
    }
}
