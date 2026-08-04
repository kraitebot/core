<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Lifecycles\Position;

use Kraite\Core\Abstracts\BaseApiableJob;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Jobs\Atomic\Position\UpdatePositionStatusJob as AtomicUpdatePositionStatusJob;
use Kraite\Core\Jobs\Lifecycles\Account\QueryAccountPositionsJob as QueryAccountPositionsLifecycle;
use Kraite\Core\Jobs\Lifecycles\Order\SyncPositionOrdersJob as SyncPositionOrdersLifecycle;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\Proxies\JobProxy;
use StepDispatcher\Models\Step;

/**
 * ClosePositionJob (Orchestrator)
 *
 * Used when TP/SL fills and position closes normally.
 * Creates steps to orderly close a position.
 *
 * Flow (8 steps):
 * 1. UpdatePositionStatusJob → status='closing'
 * 2. ClosePositionAtomicallyJob → close exposure on exchange
 * 3. CancelPositionOpenOrdersJob → remove residual reduce-only protection
 * 4. SyncPositionOrdersJob → sync orders from exchange
 * 5. QueryAccountPositionsJob → get positions snapshot
 * 6. VerifyPositionResidualAmountJob → check residual
 * 7. UpdateRemainingClosingDataJob → closing_price, was_fast_traded
 * 8. UpdatePositionStatusJob → status='closed'
 *
 * resolve-exception: UpdatePositionStatusJob → status='failed'
 *
 * Exposure is always removed before protection. If the exchange close fails,
 * the existing stop-loss and take-profit remain armed.
 */
final class ClosePositionJob extends BaseApiableJob
{
    public Position $position;

    public ?string $message;

    public bool $positionConfirmedFlat;

    public function __construct(
        int $positionId,
        ?string $message = null,
        bool $positionConfirmedFlat = false,
    ) {
        $this->position = Position::findOrFail($positionId);
        $this->message = $message;
        $this->positionConfirmedFlat = $positionConfirmedFlat;
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
     * Skip (not fail) when the position has moved past its opened statuses
     * between observer-dispatch and worker-pickup. OrderObserver fires
     * this job when a PROFIT or STOP-MARKET reaches FILLED; if another
     * workflow has already driven the position into `closing` /
     * `cancelling` / closed / failed by the time the step runs, there's
     * nothing to close — but that's an observer-race tail, not a failure.
     * Lets it land in Skipped instead of polluting the Failed bucket.
     */
    public function startOrSkip(): bool
    {
        return in_array($this->position->status, $this->position->openedStatuses(), strict: true);
    }

    public function computeApiable()
    {
        $resolver = JobProxy::with($this->position->account);

        $this->buildChildChainOnce(function (string $blockUuid) use ($resolver): void {

            // Step 1: Update status to 'closing'. Guard against a stale
            // close lifecycle clobbering newer state — only allow the
            // transition from 'active' / 'syncing' / 'opening' (the legal
            // predecessors). If a competing workflow has moved the
            // position to 'cancelling' / 'closed' / 'failed' between
            // observer dispatch and this step running, the atomic job
            // no-ops cleanly instead of overwriting.
            $statusLifecycleClass = $resolver->resolve(UpdatePositionStatusJob::class);
            $statusLifecycle = new $statusLifecycleClass($this->position);
            $nextIndex = $statusLifecycle
                ->withStatus('closing')
                ->withOnlyFromStatus(['active', 'syncing', 'opening', 'waping'])
                ->dispatch(
                    blockUuid: $blockUuid,
                    startIndex: 1,
                    workflowId: null
                );

            // Step 2: Close position on exchange unless two independent reads
            // already confirmed it flat. A redundant Bitget UTA flash-close can
            // return the ambiguous 25239 failure even though no exposure exists.
            if (! $this->positionConfirmedFlat) {
                $closeLifecycleClass = $resolver->resolve(ClosePositionAtomicallyJob::class);
                $closeLifecycle = new $closeLifecycleClass($this->position);
                $nextIndex = $closeLifecycle->dispatch(
                    blockUuid: $blockUuid,
                    startIndex: $nextIndex,
                    workflowId: null
                );
            }

            // Step 3: Only remove protection after exposure is gone. A failed
            // exchange close must leave the existing TP/SL armed.
            $cancelOrdersLifecycleClass = $resolver->resolve(CancelPositionOpenOrdersJob::class);
            $cancelOrdersLifecycle = new $cancelOrdersLifecycleClass($this->position);
            $nextIndex = $cancelOrdersLifecycle->dispatch(
                blockUuid: $blockUuid,
                startIndex: $nextIndex,
                workflowId: null
            );

            // Step 4: Sync orders from exchange
            $syncOrdersLifecycleClass = $resolver->resolve(SyncPositionOrdersLifecycle::class);
            $syncOrdersLifecycle = new $syncOrdersLifecycleClass($this->position);
            $nextIndex = $syncOrdersLifecycle->dispatch(
                blockUuid: $blockUuid,
                startIndex: $nextIndex,
                workflowId: null
            );

            // Step 5: Query account positions snapshot
            $queryPositionsLifecycleClass = $resolver->resolve(QueryAccountPositionsLifecycle::class);
            $queryPositionsLifecycle = new $queryPositionsLifecycleClass($this->position->account);
            $nextIndex = $queryPositionsLifecycle->dispatch(
                blockUuid: $blockUuid,
                startIndex: $nextIndex,
                workflowId: null
            );

            // Step 6: Verify no residual amount remains
            $verifyResidualLifecycleClass = $resolver->resolve(VerifyPositionResidualAmountJob::class);
            $verifyResidualLifecycle = new $verifyResidualLifecycleClass($this->position);
            $nextIndex = $verifyResidualLifecycle->dispatch(
                blockUuid: $blockUuid,
                startIndex: $nextIndex,
                workflowId: null
            );

            // Step 7: Update remaining closing data (close-only step)
            $updateClosingDataLifecycleClass = $resolver->resolve(UpdateRemainingClosingDataJob::class);
            $updateClosingDataLifecycle = new $updateClosingDataLifecycleClass($this->position);
            $nextIndex = $updateClosingDataLifecycle->dispatch(
                blockUuid: $blockUuid,
                startIndex: $nextIndex,
                workflowId: null
            );

            // Step 8: Update status to 'closed'. Guard against a stale
            // step transitioning a position back to 'closed' from a
            // terminal-but-different state ('cancelled' / 'failed') a
            // newer workflow may have set — only legal predecessor is
            // 'closing' itself.
            $finalStatusLifecycleClass = $resolver->resolve(UpdatePositionStatusJob::class);
            $finalStatusLifecycle = new $finalStatusLifecycleClass($this->position);
            $nextIndex = $finalStatusLifecycle
                ->withStatus('closed')
                ->withOnlyFromStatus(['closing'])
                ->dispatch(
                    blockUuid: $blockUuid,
                    startIndex: $nextIndex,
                    workflowId: null
                );

            // resolve-exception step: Update status to 'failed' if close workflow fails
            // Note: index=1 allows immediate dispatch when promoted to Pending
            Step::create([
                'class' => $resolver->resolve(AtomicUpdatePositionStatusJob::class),
                'queue' => 'positions',
                'arguments' => [
                    'positionId' => $this->position->id,
                    'status' => 'failed',
                    'message' => 'Close workflow failed: '.($this->message ?? 'Unknown error'),
                ],
                'block_uuid' => $blockUuid,
                'index' => 1,
                'type' => 'resolve-exception',
                'workflow_id' => null,
            ]);
        });

        return [
            'position_id' => $this->position->id,
            'message' => 'Close position workflow initiated',
        ];
    }
}
