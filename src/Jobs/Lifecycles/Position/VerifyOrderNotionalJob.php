<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Lifecycles\Position;

use Kraite\Core\Abstracts\BasePositionLifecycle;
use Kraite\Core\Jobs\Atomic\Position\VerifyOrderNotionalForMarketOrderJob;
use StepDispatcher\Models\Step;

/**
 * VerifyOrderNotionalJob (Lifecycle)
 *
 * Orchestrator that creates step(s) for verifying order notional.
 * Fetches mark price from exchange and validates minimum notional.
 *
 * Flow:
 * - Step N: VerifyOrderNotionalForMarketOrderJob (Atomic) - Fetches mark price, validates notional
 *
 * Must run AFTER PreparePositionDataJob (margin, leverage must be set).
 * Must run BEFORE PlaceMarketOrderJob (needs mark_price on exchange symbol).
 */
final class VerifyOrderNotionalJob extends BasePositionLifecycle
{
    public function dispatch(string $blockUuid, int $startIndex, ?string $workflowId = null): int
    {
        Step::create([
            'class' => $this->resolver->resolve(VerifyOrderNotionalForMarketOrderJob::class),
            'arguments' => ['positionId' => $this->position->id],
            'block_uuid' => $blockUuid,
            'index' => $startIndex,
            'workflow_id' => $workflowId,
        ]);

        return $startIndex + 1;
    }
}
