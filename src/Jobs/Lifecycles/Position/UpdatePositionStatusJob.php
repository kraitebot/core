<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Lifecycles\Position;

use Kraite\Core\Abstracts\BasePositionLifecycle;
use Kraite\Core\Jobs\Atomic\Position\UpdatePositionStatusJob as AtomicUpdatePositionStatusJob;
use StepDispatcher\Models\Step;

/**
 * UpdatePositionStatusJob (Lifecycle)
 *
 * Orchestrator that creates step for updating position status.
 * Delegates to the atomic job which handles the actual status transition.
 *
 * Supported statuses:
 * - cancelling, closing, closed, cancelled, failed
 * - active, watching, waping
 */
final class UpdatePositionStatusJob extends BasePositionLifecycle
{
    protected string $status;

    protected ?string $message;

    /**
     * Set the target status for this lifecycle.
     */
    public function withStatus(string $status, ?string $message = null): self
    {
        $this->status = $status;
        $this->message = $message;

        return $this;
    }

    public function dispatch(string $blockUuid, int $startIndex, ?string $workflowId = null): int
    {
        Step::create([
            'class' => $this->resolver->resolve(AtomicUpdatePositionStatusJob::class),
            'queue' => 'positions',
            'arguments' => [
                'positionId' => $this->position->id,
                'status' => $this->status,
                'message' => $this->message,
            ],
            'block_uuid' => $blockUuid,
            'index' => $startIndex,
            'workflow_id' => $workflowId,
        ]);

        return $startIndex + 1;
    }
}
