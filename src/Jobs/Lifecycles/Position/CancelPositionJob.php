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
 * CancelPositionJob (Orchestrator)
 *
 * Used as resolve-exception fallback when position opening fails.
 * Creates steps to safely cancel a position.
 *
 * Flow (7 steps):
 * 1. UpdatePositionStatusJob → status='cancelling'
 * 2. ClosePositionAtomicallyJob → close on exchange
 * 3. CancelPositionOpenOrdersJob → cancel all open orders
 * 4. SyncPositionOrdersJob → sync orders from exchange
 * 5. QueryAccountPositionsJob → get positions snapshot
 * 6. VerifyPositionResidualAmountJob → check if position still exists
 * 7. UpdatePositionStatusJob → status='cancelled'
 *
 * resolve-exception: UpdatePositionStatusJob → status='failed'
 */
final class CancelPositionJob extends BaseApiableJob
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

    public function computeApiable()
    {
        $resolver = JobProxy::with($this->position->account);

        // Atomic + idempotent chain build — a retried orchestrator must not
        // append duplicate children to a half-built or already-built block.
        $built = $this->buildChildChainOnce(function (string $blockUuid) use ($resolver): void {

            // Step 1: Update status to 'cancelling'. Only allow from
            // active-family states — a stale cancel lifecycle clobbering
            // a newer 'closing' / 'closed' / 'failed' state would corrupt
            // the position's owned-by-which-workflow contract.
            //
            // 'new' is claimable: the opening chain's resolve-exception path
            // IS this workflow, and steps 1-2 of opening (verify-pair,
            // margin-mode) fail while the position is still 'new' — the
            // status only becomes 'opening' at PreparePositionData. Without
            // 'new' here, this step no-opped, the final cancelled-from-
            // cancelling step refused, and the position stayed 'new' forever
            // — where DispatchPositionSlots re-selected it every cron cycle
            // into an infinite open-fail-"cancel" loop. The exchange-facing
            // children no-op naturally on a 'new' position (no FILLED entry,
            // no open orders), so claiming it is a pure status burial.
            $statusLifecycleClass = $resolver->resolve(UpdatePositionStatusJob::class);
            $statusLifecycle = new $statusLifecycleClass($this->position);
            $nextIndex = $statusLifecycle
                ->withStatus('cancelling')
                ->withOnlyFromStatus(['new', 'active', 'syncing', 'opening', 'waping'])
                ->dispatch(
                    blockUuid: $blockUuid,
                    startIndex: 1,
                    workflowId: null
                );

            // Step 2: Close position on exchange
            $closeLifecycleClass = $resolver->resolve(ClosePositionAtomicallyJob::class);
            $closeLifecycle = new $closeLifecycleClass($this->position);
            $nextIndex = $closeLifecycle->dispatch(
                blockUuid: $blockUuid,
                startIndex: $nextIndex,
                workflowId: null
            );

            // Step 3: Cancel all open orders
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

            // Step 7: Update status to 'cancelled'. Only legal predecessor
            // is 'cancelling' — guards against a stale step pulling a
            // closed/failed position back to 'cancelled'.
            $finalStatusLifecycleClass = $resolver->resolve(UpdatePositionStatusJob::class);
            $finalStatusLifecycle = new $finalStatusLifecycleClass($this->position);
            $nextIndex = $finalStatusLifecycle
                ->withStatus('cancelled', $this->message)
                ->withOnlyFromStatus(['cancelling'])
                ->dispatch(
                    blockUuid: $blockUuid,
                    startIndex: $nextIndex,
                    workflowId: null
                );

            // resolve-exception step: Update status to 'failed' if cancel workflow fails
            // Note: index=1 allows immediate dispatch when promoted to Pending
            Step::create([
                'class' => $resolver->resolve(AtomicUpdatePositionStatusJob::class),
                'queue' => 'positions',
                'arguments' => [
                    'positionId' => $this->position->id,
                    'status' => 'failed',
                    'message' => 'Cancel workflow failed: '.($this->message ?? 'Unknown error'),
                ],
                'block_uuid' => $blockUuid,
                'index' => 1,
                'type' => 'resolve-exception',
                'workflow_id' => null,
            ]);
        });

        return [
            'position_id' => $this->position->id,
            'message' => $built ? 'Cancel position workflow initiated' : 'Retry detected — child block already populated, no-op.',
        ];
    }
}
