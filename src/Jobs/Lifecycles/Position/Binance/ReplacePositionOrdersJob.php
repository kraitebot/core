<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Lifecycles\Position\Binance;

use Kraite\Core\Jobs\Lifecycles\Order\PlaceLimitOrdersJob as PlaceLimitOrdersLifecycle;
use Kraite\Core\Jobs\Lifecycles\Order\PlaceProfitOrderJob as PlaceProfitOrderLifecycle;
use Kraite\Core\Jobs\Lifecycles\Order\PlaceStopLossOrderJob as PlaceStopLossOrderLifecycle;
use Kraite\Core\Jobs\Lifecycles\Order\SyncPositionOrdersJob as SyncPositionOrdersLifecycle;
use Kraite\Core\Jobs\Lifecycles\Position\ActivatePositionJob as ActivatePositionLifecycle;
use Kraite\Core\Jobs\Lifecycles\Position\CancelPositionJob;
use Kraite\Core\Jobs\Lifecycles\Position\CancelPositionOpenOrdersJob;
use Kraite\Core\Jobs\Lifecycles\Position\ReplacePositionOrdersJob as BaseReplacePositionOrdersJob;
use Kraite\Core\Jobs\Lifecycles\Position\UpdatePositionStatusJob;
use Kraite\Core\Support\Proxies\JobProxy;
use StepDispatcher\Models\Step;

/**
 * ReplacePositionOrdersJob (Orchestrator) - Binance
 *
 * Replaces missing orders when position still exists on exchange.
 *
 * Flow (7 steps):
 * 1. UpdatePositionStatusJob → status='syncing'
 * 2. CancelPositionOpenOrdersJob → cancel remaining orders on exchange
 * 3. SyncPositionOrdersJob → sync all orders from exchange
 * 4. PlaceLimitOrdersJob → recreate limit ladder
 * 5. PlaceProfitOrderJob → recreate take-profit order
 * 6. PlaceStopLossOrderJob → recreate stop-loss order
 * 7. ActivatePositionJob → validate orders, set status='active'
 *
 * resolve-exception: CancelPositionJob → if replacement fails, cancel the position
 */
final class ReplacePositionOrdersJob extends BaseReplacePositionOrdersJob
{
    public function computeApiable()
    {
        $resolver = JobProxy::with($this->position->account);

        $this->buildChildChainOnce(function (string $blockUuid) use ($resolver): void {

            // Step 1: Update status to 'syncing'
            $statusLifecycleClass = $resolver->resolve(UpdatePositionStatusJob::class);
            $statusLifecycle = new $statusLifecycleClass($this->position);
            $nextIndex = $statusLifecycle->withStatus('syncing')->dispatch(
                blockUuid: $blockUuid,
                startIndex: 1,
                workflowId: null
            );

            // Step 2: Cancel remaining orders on exchange
            $cancelOrdersLifecycleClass = $resolver->resolve(CancelPositionOpenOrdersJob::class);
            $cancelOrdersLifecycle = new $cancelOrdersLifecycleClass($this->position);
            $nextIndex = $cancelOrdersLifecycle->dispatch(
                blockUuid: $blockUuid,
                startIndex: $nextIndex,
                workflowId: null
            );

            // Step 3: Sync all orders from exchange
            $syncOrdersLifecycleClass = $resolver->resolve(SyncPositionOrdersLifecycle::class);
            $syncOrdersLifecycle = new $syncOrdersLifecycleClass($this->position);
            $nextIndex = $syncOrdersLifecycle->dispatch(
                blockUuid: $blockUuid,
                startIndex: $nextIndex,
                workflowId: null
            );

            // Step 4: Recreate limit ladder orders
            $placeLimitOrdersLifecycleClass = $resolver->resolve(PlaceLimitOrdersLifecycle::class);
            $placeLimitOrdersLifecycle = new $placeLimitOrdersLifecycleClass($this->position);
            $nextIndex = $placeLimitOrdersLifecycle->dispatch(
                blockUuid: $blockUuid,
                startIndex: $nextIndex,
                workflowId: null
            );

            // Step 5: Recreate take-profit order
            $placeProfitOrderLifecycleClass = $resolver->resolve(PlaceProfitOrderLifecycle::class);
            $placeProfitOrderLifecycle = new $placeProfitOrderLifecycleClass($this->position);
            $nextIndex = $placeProfitOrderLifecycle->dispatch(
                blockUuid: $blockUuid,
                startIndex: $nextIndex,
                workflowId: null
            );

            // Step 6: Recreate stop-loss order
            $placeStopLossLifecycleClass = $resolver->resolve(PlaceStopLossOrderLifecycle::class);
            $placeStopLossLifecycle = new $placeStopLossLifecycleClass($this->position);
            $nextIndex = $placeStopLossLifecycle->dispatch(
                blockUuid: $blockUuid,
                startIndex: $nextIndex,
                workflowId: null
            );

            // Step 7: Activate position (validate orders, set status='active')
            $activatePositionLifecycleClass = $resolver->resolve(ActivatePositionLifecycle::class);
            $activatePositionLifecycle = new $activatePositionLifecycleClass($this->position);
            $activatePositionLifecycle->dispatch(
                blockUuid: $blockUuid,
                startIndex: $nextIndex,
                workflowId: null
            );

            // resolve-exception: Cancel position if replacement workflow fails
            Step::create([
                'class' => $resolver->resolve(CancelPositionJob::class),
                'queue' => 'positions',
                'block_uuid' => $blockUuid,
                'index' => 1,
                'type' => 'resolve-exception',
                'arguments' => [
                    'positionId' => $this->position->id,
                    'message' => 'Position replacement failed: '.($this->message ?? 'Unknown error'),
                ],
            ]);
        });

        return [
            'position_id' => $this->position->id,
            'message' => 'Position order replacement initiated',
        ];
    }
}
