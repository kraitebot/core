<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Lifecycles\Position;

use Kraite\Core\Abstracts\BaseApiableJob;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Jobs\Atomic\Order\CalculateWapAndModifyProfitOrderJob;
use Kraite\Core\Jobs\Atomic\Order\VerifyIfTPIsFilledJob;
use Kraite\Core\Jobs\Atomic\Position\UpdatePositionStatusJob as AtomicUpdatePositionStatusJob;
use Kraite\Core\Jobs\Lifecycles\Account\QueryAccountPositionsJob as QueryAccountPositionsLifecycle;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\Proxies\JobProxy;
use StepDispatcher\Models\Step;

/**
 * ApplyWapJob (Lifecycle Orchestrator)
 *
 * Dispatched when a LIMIT (DCA) order is filled.
 * Recalculates the take-profit price based on Binance's breakEvenPrice
 * and modifies the PROFIT order accordingly.
 *
 * Flow (5 steps):
 * 1. UpdatePositionStatusJob → status='waping'
 * 2. VerifyIfTPIsFilledJob → queries exchange, aborts if TP already filled
 * 3. QueryAccountPositionsJob → fetches fresh data with breakEvenPrice
 * 4. CalculateWapAndModifyProfitOrderJob → the WAP calculation
 * 5. UpdatePositionStatusJob → status='active'
 * + resolve-exception: UpdatePositionStatusJob → status='active' (revert on failure)
 */
final class ApplyWapJob extends BaseApiableJob
{
    public Position $position;

    public ?string $message;

    public function __construct(int $positionId, ?string $message = null)
    {
        $this->position = Position::findOrFail($positionId);
        $this->message = $message;
    }

    public function assignExceptionHandler(): void
    {
        $canonical = $this->position->account->apiSystem->canonical;
        $this->exceptionHandler = BaseExceptionHandler::make($canonical)
            ->withAccount($this->position->account);
    }

    public function relatable()
    {
        return $this->position;
    }

    /**
     * Guard: Only run if position is still in an active status.
     * Prevents WAP on positions already closing/closed.
     */
    public function startOrFail(): bool
    {
        // Position must be in an active status
        if (! in_array($this->position->status, $this->position->activeStatuses(), true)) {
            return false;
        }

        // Must have a profit order to modify
        if ($this->position->profitOrder() === null) {
            return false;
        }

        // Must have profit_percentage configured
        if ($this->position->profit_percentage === null) {
            return false;
        }

        return true;
    }

    public function computeApiable()
    {
        $resolver = JobProxy::with($this->position->account);
        $blockUuid = $this->uuid();

        // Step 1: Update position status to 'waping'.
        // Guarded with onlyFromStatus='active': if a concurrent close workflow
        // has already transitioned the position to 'closing' between our
        // orchestrator's startOrFail and this step, skip rather than revive it.
        Step::create([
            'class' => $resolver->resolve(AtomicUpdatePositionStatusJob::class),
            'arguments' => [
                'positionId' => $this->position->id,
                'status' => 'waping',
                'message' => $this->message,
                'onlyFromStatus' => 'active',
            ],
            'block_uuid' => $blockUuid,
            'index' => 1,
            'workflow_id' => null,
        ]);

        // Step 2: Verify TP is not already filled on exchange
        // This catches the edge case where LIMIT and TP fill in same sync cycle
        Step::create([
            'class' => $resolver->resolve(VerifyIfTPIsFilledJob::class),
            'arguments' => [
                'positionId' => $this->position->id,
            ],
            'block_uuid' => $blockUuid,
            'index' => 2,
            'workflow_id' => null,
        ]);

        // Step 3: Query account positions snapshot from exchange
        $queryPositionsLifecycleClass = $resolver->resolve(QueryAccountPositionsLifecycle::class);
        $queryPositionsLifecycle = new $queryPositionsLifecycleClass($this->position->account);
        $nextIndex = $queryPositionsLifecycle->dispatch(
            blockUuid: $blockUuid,
            startIndex: 3,
            workflowId: null
        );

        // Step 4: Calculate WAP and modify profit order
        Step::create([
            'class' => $resolver->resolve(CalculateWapAndModifyProfitOrderJob::class),
            'arguments' => [
                'positionId' => $this->position->id,
            ],
            'block_uuid' => $blockUuid,
            'index' => $nextIndex,
            'workflow_id' => null,
        ]);
        $nextIndex++;

        // Step 5: Revert position status to 'active'.
        // Guarded with onlyFromStatus='waping': if a concurrent close workflow
        // triggered by a TP fill that slipped past VerifyIfTPIsFilledJob has
        // already set the position to 'closing', do NOT promote it back to
        // 'active' — that would silently break the close workflow's state
        // machine. In that case the current status is 'closing' and this
        // transition no-ops, which is the correct outcome.
        Step::create([
            'class' => $resolver->resolve(AtomicUpdatePositionStatusJob::class),
            'arguments' => [
                'positionId' => $this->position->id,
                'status' => 'active',
                'message' => 'WAP applied successfully',
                'onlyFromStatus' => 'waping',
            ],
            'block_uuid' => $blockUuid,
            'index' => $nextIndex,
            'workflow_id' => null,
        ]);

        // Resolve-exception step: Revert status to 'active' on failure.
        // Same onlyFromStatus='waping' guard — if the failure path fires
        // while a close workflow has already claimed the position, let the
        // close workflow's state advance unmolested.
        Step::create([
            'class' => $resolver->resolve(AtomicUpdatePositionStatusJob::class),
            'arguments' => [
                'positionId' => $this->position->id,
                'status' => 'active',
                'message' => 'WAP failed, reverting to active',
                'onlyFromStatus' => 'waping',
            ],
            'block_uuid' => $blockUuid,
            'index' => 1,
            'type' => 'resolve-exception',
            'workflow_id' => null,
        ]);

        return [
            'position_id' => $this->position->id,
            'message' => 'WAP workflow initiated',
        ];
    }
}
