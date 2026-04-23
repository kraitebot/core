<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Lifecycles\Position;

use Kraite\Core\Abstracts\BasePositionLifecycle;
use Kraite\Core\Jobs\Atomic\Position\VerifyPositionStillOpenJob as VerifyPositionStillOpenAtomic;
use StepDispatcher\Models\Step;

/**
 * VerifyPositionStillOpenJob (Lifecycle)
 *
 * Orchestrator wrapper for the `VerifyPositionStillOpenJob` atomic.
 * Injected between PlaceProfitOrderJob and PlaceStopLossOrderJob in
 * every exchange-specific DispatchPositionJob so a TP fill that races
 * the SL placement aborts the workflow cleanly instead of Binance /
 * Bybit / KuCoin / Bitget rejecting the SL with an "open position
 * required" vendor error.
 *
 * Flow:
 * - Step N: VerifyPositionStillOpenJob (Atomic) — fails with a
 *   NonNotifiableException if the exchange no longer carries the
 *   position for this symbol + direction.
 */
final class VerifyPositionStillOpenJob extends BasePositionLifecycle
{
    public function dispatch(string $blockUuid, int $startIndex, ?string $workflowId = null): int
    {
        Step::create([
            'class' => $this->resolver->resolve(VerifyPositionStillOpenAtomic::class),
            'queue' => 'positions',
            'arguments' => ['positionId' => $this->position->id],
            'block_uuid' => $blockUuid,
            'index' => $startIndex,
            'workflow_id' => $workflowId,
        ]);

        return $startIndex + 1;
    }
}
