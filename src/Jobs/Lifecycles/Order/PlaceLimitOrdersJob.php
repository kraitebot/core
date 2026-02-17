<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Lifecycles\Order;

use Illuminate\Support\Str;
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
class PlaceLimitOrdersJob extends BasePositionLifecycle
{
    public function dispatch(string $blockUuid, int $startIndex, ?string $workflowId = null): int
    {
        Step::create([
            'class' => $this->resolver->resolve(DispatchLimitOrdersJob::class),
            'arguments' => ['positionId' => $this->position->id],
            'block_uuid' => $blockUuid,
            'child_block_uuid' => (string) Str::uuid(),
            'index' => $startIndex,
            'workflow_id' => $workflowId,
        ]);

        return $startIndex + 1;
    }
}
