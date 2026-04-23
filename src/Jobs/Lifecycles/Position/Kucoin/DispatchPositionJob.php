<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Lifecycles\Position\Kucoin;

use Kraite\Core\Jobs\Lifecycles\Order\PlaceLimitOrdersJob as PlaceLimitOrdersLifecycle;
use Kraite\Core\Jobs\Lifecycles\Order\PlaceMarketOrderJob as PlaceMarketOrderLifecycle;
use Kraite\Core\Jobs\Lifecycles\Order\PlaceProfitOrderJob as PlaceProfitOrderLifecycle;
use Kraite\Core\Jobs\Lifecycles\Order\PlaceStopLossOrderJob as PlaceStopLossOrderLifecycle;
use Kraite\Core\Jobs\Lifecycles\Position\ActivatePositionJob as ActivatePositionLifecycle;
use Kraite\Core\Jobs\Lifecycles\Position\CancelPositionJob;
use Kraite\Core\Jobs\Lifecycles\Position\DetermineLeverageJob as DetermineLeverageLifecycle;
use Kraite\Core\Jobs\Lifecycles\Position\DispatchPositionJob as BaseDispatchPositionJob;
use Kraite\Core\Jobs\Lifecycles\Position\PreparePositionDataJob as PreparePositionDataLifecycle;
use Kraite\Core\Jobs\Lifecycles\Position\SetLeverageJob as SetLeverageLifecycle;
use Kraite\Core\Jobs\Lifecycles\Position\SetMarginModeJob as SetMarginModeLifecycle;
use Kraite\Core\Jobs\Lifecycles\Position\VerifyOrderNotionalJob as VerifyOrderNotionalLifecycle;
use Kraite\Core\Jobs\Lifecycles\Position\VerifyTradingPairNotOpenJob as VerifyTradingPairNotOpenLifecycle;
use Kraite\Core\Support\Proxies\JobProxy;
use StepDispatcher\Models\Step;

/**
 * DispatchPositionJob (Orchestrator) - KuCoin
 *
 * Orchestrator that creates step(s) for dispatching a position to KuCoin.
 *
 * Flow:
 * • Step 1: VerifyTradingPairNotOpenJob - Verify pair not already open (showstopper)
 * • Step 2: SetMarginModeJob - Set margin mode (isolated/cross)
 * • Step 3: PreparePositionDataJob - Populate margin, indicators (no leverage)
 * • Step 4: DetermineLeverageJob - Determine optimal leverage based on margin and brackets
 * • Step 5: SetLeverageJob - Set leverage on exchange
 * • Step 6: VerifyOrderNotionalJob - Fetch mark price, validate notional
 * • Step 7: PlaceMarketOrderJob - Place market entry order
 * • Step 8: PlaceLimitOrdersJob - Place limit ladder orders (parallel)
 * • Step 9: PlaceStopLossOrderJob - Place stop-loss FIRST (protects position before TP can fire)
 * • Step 10: PlaceProfitOrderJob - Place take-profit order
 * • Step 11: ActivatePositionJob - Validate orders, set status='active'
 */
final class DispatchPositionJob extends BaseDispatchPositionJob
{
    public function compute()
    {
        $resolver = JobProxy::with($this->position->account);

        // Step 1: Verify trading pair not already open
        $verifyLifecycleClass = $resolver->resolve(VerifyTradingPairNotOpenLifecycle::class);
        $verifyLifecycle = new $verifyLifecycleClass($this->position);
        $nextIndex = $verifyLifecycle->dispatch(
            blockUuid: $this->uuid(),
            startIndex: 1,
            workflowId: null
        );

        // Step 2: Set margin mode (isolated/crossed)
        $marginModeLifecycleClass = $resolver->resolve(SetMarginModeLifecycle::class);
        $marginModeLifecycle = new $marginModeLifecycleClass($this->position);
        $nextIndex = $marginModeLifecycle->dispatch(
            blockUuid: $this->uuid(),
            startIndex: $nextIndex,
            workflowId: null
        );

        // Step 3: Prepare position data (margin, indicators - leverage determined next)
        $prepareDataLifecycleClass = $resolver->resolve(PreparePositionDataLifecycle::class);
        $prepareDataLifecycle = new $prepareDataLifecycleClass($this->position);
        $nextIndex = $prepareDataLifecycle->dispatch(
            blockUuid: $this->uuid(),
            startIndex: $nextIndex,
            workflowId: null
        );

        // Step 4: Determine optimal leverage based on margin and brackets
        $determineLeverageLifecycleClass = $resolver->resolve(DetermineLeverageLifecycle::class);
        $determineLeverageLifecycle = new $determineLeverageLifecycleClass($this->position);
        $nextIndex = $determineLeverageLifecycle->dispatch(
            blockUuid: $this->uuid(),
            startIndex: $nextIndex,
            workflowId: null
        );

        // Step 5: Set leverage on exchange
        $leverageLifecycleClass = $resolver->resolve(SetLeverageLifecycle::class);
        $leverageLifecycle = new $leverageLifecycleClass($this->position);
        $nextIndex = $leverageLifecycle->dispatch(
            blockUuid: $this->uuid(),
            startIndex: $nextIndex,
            workflowId: null
        );

        // Step 6: Verify order notional (fetch mark price, validate notional)
        $verifyNotionalLifecycleClass = $resolver->resolve(VerifyOrderNotionalLifecycle::class);
        $verifyNotionalLifecycle = new $verifyNotionalLifecycleClass($this->position);
        $nextIndex = $verifyNotionalLifecycle->dispatch(
            blockUuid: $this->uuid(),
            startIndex: $nextIndex,
            workflowId: null
        );

        // Step 7: Place entry order
        $placeEntryLifecycleClass = $resolver->resolve(PlaceMarketOrderLifecycle::class);
        $placeEntryLifecycle = new $placeEntryLifecycleClass($this->position);
        $nextIndex = $placeEntryLifecycle->dispatch(
            blockUuid: $this->uuid(),
            startIndex: $nextIndex,
            workflowId: null
        );

        // Step 8: Place limit ladder orders (parallel)
        $placeLimitOrdersLifecycleClass = $resolver->resolve(PlaceLimitOrdersLifecycle::class);
        $placeLimitOrdersLifecycle = new $placeLimitOrdersLifecycleClass($this->position);
        $nextIndex = $placeLimitOrdersLifecycle->dispatch(
            blockUuid: $this->uuid(),
            startIndex: $nextIndex,
            workflowId: null
        );

        // Step 9: Place stop-loss order FIRST — see Binance variant for
        // the full rationale. TL;DR: placing the SL before the TP turns
        // a TOCTOU race into an invariant, since the SL is a conditional
        // algo that can't fire at placement time.
        $placeStopLossOrderLifecycleClass = $resolver->resolve(PlaceStopLossOrderLifecycle::class);
        $placeStopLossOrderLifecycle = new $placeStopLossOrderLifecycleClass($this->position);
        $nextIndex = $placeStopLossOrderLifecycle->dispatch(
            blockUuid: $this->uuid(),
            startIndex: $nextIndex,
            workflowId: null
        );

        // Step 10: Place take-profit order
        $placeProfitOrderLifecycleClass = $resolver->resolve(PlaceProfitOrderLifecycle::class);
        $placeProfitOrderLifecycle = new $placeProfitOrderLifecycleClass($this->position);
        $nextIndex = $placeProfitOrderLifecycle->dispatch(
            blockUuid: $this->uuid(),
            startIndex: $nextIndex,
            workflowId: null
        );

        // Step 11: Activate position (validate orders, set status='active')
        $activatePositionLifecycleClass = $resolver->resolve(ActivatePositionLifecycle::class);
        $activatePositionLifecycle = new $activatePositionLifecycleClass($this->position);
        $nextIndex = $activatePositionLifecycle->dispatch(
            blockUuid: $this->uuid(),
            startIndex: $nextIndex,
            workflowId: null
        );

        // resolve-exception: Cancel position if any step fails
        // Note: index=1 allows immediate dispatch when promoted to Pending
        Step::create([
            'class' => $resolver->resolve(CancelPositionJob::class),
            'queue' => 'positions',
            'block_uuid' => $this->uuid(),
            'index' => 1,
            'type' => 'resolve-exception',
            'arguments' => [
                'positionId' => $this->position->id,
                'message' => 'Position opening failed during dispatch workflow',
            ],
        ]);

        return [
            'position_id' => $this->position->id,
            'message' => 'Position dispatching initiated',
        ];
    }
}
