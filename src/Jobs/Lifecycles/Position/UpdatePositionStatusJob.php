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
     * Optional list of allowed prior statuses. The atomic job's
     * `onlyFromStatus` gate no-ops the transition when the position's
     * current status isn't in this list — prevents a stale lifecycle
     * step from overwriting a newer competing workflow's state during
     * observer/sync races. When null, the atomic job applies the
     * transition unconditionally (legacy behaviour).
     *
     * @var array<int, string>|null
     */
    protected ?array $onlyFromStatus = null;

    /**
     * Set the target status for this lifecycle.
     */
    public function withStatus(string $status, ?string $message = null): self
    {
        $this->status = $status;
        $this->message = $message;

        return $this;
    }

    /**
     * Constrain this transition to only fire when the position's
     * current status matches one of the supplied prior states. Pass
     * the full list of acceptable predecessors (e.g. `['active',
     * 'syncing']`); the atomic job no-ops cleanly otherwise.
     *
     * @param  array<int, string>  $statuses
     */
    public function withOnlyFromStatus(array $statuses): self
    {
        $this->onlyFromStatus = $statuses;

        return $this;
    }

    public function dispatch(string $blockUuid, int $startIndex, ?string $workflowId = null): int
    {
        $arguments = [
            'positionId' => $this->position->id,
            'status' => $this->status,
            'message' => $this->message,
        ];

        if ($this->onlyFromStatus !== null) {
            $arguments['onlyFromStatus'] = $this->onlyFromStatus;
        }

        Step::create([
            'class' => $this->resolver->resolve(AtomicUpdatePositionStatusJob::class),
            'queue' => 'positions',
            'arguments' => $arguments,
            'block_uuid' => $blockUuid,
            'index' => $startIndex,
            'workflow_id' => $workflowId,
        ]);

        return $startIndex + 1;
    }
}
