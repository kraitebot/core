<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Lifecycles\Position;

use Kraite\Core\Abstracts\BasePositionLifecycle;
use Kraite\Core\Jobs\Atomic\Position\SetLeverageJob as AtomicSetLeverageJob;
use StepDispatcher\Models\Step;

/**
 * SetLeverageJob (Lifecycle)
 *
 * Orchestrator that creates step(s) for setting leverage on the exchange.
 * Default implementation creates a single atomic step.
 * Exchange-specific overrides can add additional logic if needed.
 *
 * Flow:
 * - Step N: SetLeverageJob (Atomic) - Sets leverage ratio on exchange
 */
final class SetLeverageJob extends BasePositionLifecycle
{
    public function dispatch(string $blockUuid, int $startIndex, ?string $workflowId = null): int
    {
        Step::create([
            'class' => $this->resolver->resolve(AtomicSetLeverageJob::class),
            'queue' => 'positions',
            'arguments' => ['positionId' => $this->position->id],
            'block_uuid' => $blockUuid,
            'index' => $startIndex,
            'workflow_id' => $workflowId,
        ]);

        return $startIndex + 1;
    }
}
