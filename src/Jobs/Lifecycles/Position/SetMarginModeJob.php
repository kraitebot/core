<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Lifecycles\Position;

use Kraite\Core\Abstracts\BasePositionLifecycle;
use Kraite\Core\Jobs\Atomic\Position\SetMarginModeJob as AtomicSetMarginModeJob;
use StepDispatcher\Models\Step;

/**
 * SetMarginModeJob (Lifecycle)
 *
 * Orchestrator that creates step(s) for setting margin mode on the exchange.
 * Default implementation creates a single atomic step.
 * Exchange-specific overrides can add additional logic if needed.
 *
 * Flow:
 * - Step N: SetMarginModeJob (Atomic) - Sets margin mode (isolated/crossed) on exchange
 */
class SetMarginModeJob extends BasePositionLifecycle
{
    public function dispatch(string $blockUuid, int $startIndex, ?string $workflowId = null): int
    {
        Step::create([
            'class' => $this->resolver->resolve(AtomicSetMarginModeJob::class),
            'arguments' => ['positionId' => $this->position->id],
            'block_uuid' => $blockUuid,
            'index' => $startIndex,
            'workflow_id' => $workflowId,
        ]);

        return $startIndex + 1;
    }
}
