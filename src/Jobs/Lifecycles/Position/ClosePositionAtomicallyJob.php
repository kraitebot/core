<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Lifecycles\Position;

use Kraite\Core\Abstracts\BasePositionLifecycle;
use Kraite\Core\Jobs\Atomic\Position\ClosePositionAtomicallyJob as AtomicClosePositionAtomicallyJob;
use StepDispatcher\Models\Step;

/**
 * ClosePositionAtomicallyJob (Lifecycle)
 *
 * Orchestrator that creates step for closing a position on the exchange.
 * Includes pump cooldown logic when price spikes above threshold.
 */
class ClosePositionAtomicallyJob extends BasePositionLifecycle
{
    protected bool $verifyPrice = false;

    /**
     * Set whether to verify price before closing (used by cancel workflow).
     */
    public function withVerifyPrice(bool $verifyPrice = true): self
    {
        $this->verifyPrice = $verifyPrice;

        return $this;
    }

    public function dispatch(string $blockUuid, int $startIndex, ?string $workflowId = null): int
    {
        Step::create([
            'class' => $this->resolver->resolve(AtomicClosePositionAtomicallyJob::class),
            'arguments' => [
                'positionId' => $this->position->id,
                'verifyPrice' => $this->verifyPrice,
            ],
            'block_uuid' => $blockUuid,
            'index' => $startIndex,
            'workflow_id' => $workflowId,
        ]);

        return $startIndex + 1;
    }
}
