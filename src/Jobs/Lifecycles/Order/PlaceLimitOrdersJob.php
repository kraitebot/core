<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Lifecycles\Order;

use Kraite\Core\Abstracts\BasePositionLifecycle;
use Kraite\Core\Jobs\Atomic\Order\DispatchLimitOrdersJob;
use StepDispatcher\Models\Step;

/**
 * PlaceLimitOrdersJob (Lifecycle)
 *
 * Orchestrator that creates a step for dispatching limit orders.
 * The actual ladder calculation and step creation is done by DispatchLimitOrdersJob.
 *
 * Flow:
 * - Step N: DispatchLimitOrdersJob - Calculates ladder, creates N parallel steps
 *
 * Must run AFTER PlaceMarketOrderJob (position must have quantity and opening_price).
 */
final class PlaceLimitOrdersJob extends BasePositionLifecycle
{
    public function dispatch(string $blockUuid, int $startIndex, ?string $workflowId = null): int
    {
        Step::create([
            'class' => $this->resolver->resolve(DispatchLimitOrdersJob::class),
            'queue' => 'positions',
            'arguments' => ['positionId' => $this->position->id],
            'block_uuid' => $blockUuid,
            'index' => $startIndex,
            'workflow_id' => $workflowId,
        ]);

        return $startIndex + 1;
    }
}
