<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Lifecycles\Order;

use Kraite\Core\Abstracts\BasePositionLifecycle;
use Kraite\Core\Jobs\Atomic\Order\PlaceProfitOrderJob as PlaceProfitOrderAtomic;
use StepDispatcher\Models\Step;

/**
 * PlaceProfitOrderJob (Lifecycle)
 *
 * Orchestrator that creates step(s) for placing the take-profit order.
 *
 * Flow:
 * - Step N: PlaceProfitOrderJob (Atomic) - Places take-profit order
 *
 * Must run AFTER PlaceMarketOrderJob (position must have opening_price and quantity).
 */
final class PlaceProfitOrderJob extends BasePositionLifecycle
{
    public function dispatch(string $blockUuid, int $startIndex, ?string $workflowId = null): int
    {
        Step::create([
            'class' => $this->resolver->resolve(PlaceProfitOrderAtomic::class),
            'queue' => 'positions',
            'arguments' => ['positionId' => $this->position->id],
            'block_uuid' => $blockUuid,
            'index' => $startIndex,
            'workflow_id' => $workflowId,
        ]);

        return $startIndex + 1;
    }
}
