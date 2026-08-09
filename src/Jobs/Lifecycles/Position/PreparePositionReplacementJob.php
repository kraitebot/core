<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Lifecycles\Position;

use Kraite\Core\Abstracts\BaseApiableJob;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Jobs\Atomic\Position\VerifyPositionExistsOnExchangeJob;
use Kraite\Core\Jobs\Lifecycles\Account\QueryAccountPositionsJob as QueryAccountPositionsLifecycle;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\Proxies\JobProxy;
use StepDispatcher\Models\Step;

/**
 * PreparePositionReplacementJob (Orchestrator)
 *
 * Dispatched when a profit/stop order is CANCELLED or EXPIRED.
 * Queries the exchange to determine if the position still exists,
 * then delegates to the appropriate workflow:
 *
 * Flow (2 steps):
 * 1. QueryAccountPositionsJob → fetch fresh positions snapshot
 * 2. VerifyPositionExistsOnExchangeJob → read snapshot and decide:
 *    - Position GONE → dispatches CancelPositionJob
 *    - Position EXISTS → dispatches ReplacePositionOrdersJob
 */
final class PreparePositionReplacementJob extends BaseApiableJob
{
    public Position $position;

    public string $triggerStatus;

    public ?string $message;

    public function __construct(
        int $positionId,
        string $triggerStatus,
        ?string $message = null,
        public bool $confirmationAttempt = false,
        public bool $manualCloseDetected = false,
        public ?int $flatObservedAtMs = null,
        public ?string $manualClosingPrice = null,
    ) {
        $this->position = Position::findOrFail($positionId);
        $this->triggerStatus = $triggerStatus;
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
     * Skip (not fail) if the position has moved past its active statuses
     * between observer-dispatch and worker-pickup.
     *
     * The OrderObserver fires this job when a LIMIT / PROFIT / STOP order
     * transitions to CANCELLED or EXPIRED. By the time the queued step
     * runs, the position may already be closing (TP filled, SL filled,
     * user-triggered cancel). Nothing to replace — but it's not a failure
     * either, it's just that the precondition stopped applying. Routing
     * to `startOrSkip` lands the step in the Skipped terminal state
     * instead of polluting the Failed bucket.
     */
    public function startOrSkip(): bool
    {
        return in_array($this->position->status, $this->position->activeStatuses(), strict: true);
    }

    public function computeApiable()
    {
        $resolver = JobProxy::with($this->position->account);

        $this->buildChildChainOnce(function (string $blockUuid) use ($resolver): void {
            // Step 1: Query account positions snapshot from exchange
            $queryPositionsLifecycleClass = $resolver->resolve(QueryAccountPositionsLifecycle::class);
            $queryPositionsLifecycle = new $queryPositionsLifecycleClass($this->position->account);
            $nextIndex = $queryPositionsLifecycle->dispatch(
                blockUuid: $blockUuid,
                startIndex: 1,
                workflowId: null
            );

            // Step 2: Verify position exists on exchange and dispatch appropriate workflow
            $verifyExistsClass = $resolver->resolve(VerifyPositionExistsOnExchangeJob::class);
            Step::create([
                'class' => $verifyExistsClass,
                'queue' => 'positions',
                'arguments' => [
                    'positionId' => $this->position->id,
                    'triggerStatus' => $this->triggerStatus,
                    'message' => $this->message,
                    'confirmationAttempt' => $this->confirmationAttempt,
                    'manualCloseDetected' => $this->manualCloseDetected,
                    'flatObservedAtMs' => $this->flatObservedAtMs,
                    'manualClosingPrice' => $this->manualClosingPrice,
                ],
                'block_uuid' => $blockUuid,
                'index' => $nextIndex,
                'workflow_id' => null,
            ]);
        });

        return [
            'position_id' => $this->position->id,
            'trigger_status' => $this->triggerStatus,
            'confirmation_attempt' => $this->confirmationAttempt,
            'message' => 'Position replacement workflow initiated',
        ];
    }
}
