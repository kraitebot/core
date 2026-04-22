<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Lifecycles\Order;

use Kraite\Core\Abstracts\BasePositionLifecycle;
use Kraite\Core\Jobs\Atomic\Order\PlaceStopLossOrderJob as PlaceStopLossOrderAtomic;
use StepDispatcher\Models\Step;

/**
 * PlaceStopLossOrderJob (Lifecycle)
 *
 * Orchestrator that creates step(s) for placing the stop-loss order.
 *
 * Flow:
 * - Step N: PlaceStopLossOrderJob (Atomic) - Places stop-loss order
 *
 * Must run AFTER PlaceLimitOrdersJob (needs limit order prices for anchor).
 */
final class PlaceStopLossOrderJob extends BasePositionLifecycle
{
    public function dispatch(string $blockUuid, int $startIndex, ?string $workflowId = null): int
    {
        Step::create([
            'class' => $this->resolver->resolve(PlaceStopLossOrderAtomic::class),
            'queue' => 'positions',
            'arguments' => ['positionId' => $this->position->id],
            'block_uuid' => $blockUuid,
            'index' => $startIndex,
            'workflow_id' => $workflowId,
        ]);

        return $startIndex + 1;
    }
}
