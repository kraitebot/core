<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Lifecycles\Order\Bitget;

use Kraite\Core\Abstracts\BasePositionLifecycle;
use Kraite\Core\Jobs\Atomic\Order\Bitget\PlacePositionTpslJob as PlacePositionTpslAtomic;
use StepDispatcher\Models\Step;

/**
 * PlacePositionTpslJob (Lifecycle) - Bitget
 *
 * Orchestrator that creates step(s) for placing combined position TP/SL.
 *
 * Flow:
 * - Step N: PlacePositionTpslJob (Atomic) - Places both TP and SL in one API call
 *
 * Must run AFTER PlaceMarketOrderJob (position must have opening_price and quantity)
 * and AFTER PlaceLimitOrdersJob (needs anchor price from last limit order).
 */
final class PlacePositionTpslJob extends BasePositionLifecycle
{
    public function dispatch(string $blockUuid, int $startIndex, ?string $workflowId = null): int
    {
        Step::create([
            'class' => $this->resolver->resolve(PlacePositionTpslAtomic::class),
            'queue' => 'positions',
            'arguments' => ['positionId' => $this->position->id],
            'block_uuid' => $blockUuid,
            'index' => $startIndex,
            'workflow_id' => $workflowId,
        ]);

        return $startIndex + 1;
    }
}
