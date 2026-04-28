<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Lifecycles\Position;

use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Jobs\Lifecycles\Position\CancelPositionOpenOrdersJob as CancelPositionOpenOrdersLifecycle;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\Proxies\JobProxy;

/**
 * PrepareCancelOrphanOrdersJob (Orchestrator)
 *
 * Top-level Step dispatched by the drift spotter (kraite:cron-check-drifts)
 * when it finds open orders attached to a position that has already
 * resolved to closed / cancelled / failed.
 *
 * Reuses the production close-workflow cancel machinery (the
 * CancelPositionOpenOrders lifecycle) end-to-end:
 *  - Atomic\Position\CancelPositionOpenOrdersJob: bulk cancel-all on
 *    regular (non-algo) orders, with Bitget's per-order fallback.
 *  - Atomic\Position\CancelAlgoOpenOrdersJob: per-order apiCancel for
 *    algo orders, with the existing ghost guard
 *    (whereNotNull('exchange_order_id')) baked in.
 *
 * The wrapper exists for two reasons:
 *  1. The spotter's Step::create call needs a single self-contained
 *     entry point — like PrepareSyncOrdersJob — rather than reaching
 *     into the lifecycle's dispatch() method directly.
 *  2. The status guard belongs at the orchestrator level so the
 *     spotter can dispatch optimistically; if the parent position has
 *     since transitioned back to active (race against the close
 *     workflow), this job no-ops cleanly instead of cancelling a
 *     legitimately live position's orders.
 */
final class PrepareCancelOrphanOrdersJob extends BaseQueueableJob
{
    /**
     * Position statuses that legitimately carry orphan orders. Mirrors
     * the spotter's Scope 2 query so the post-dispatch race-check here
     * is consistent with the upstream filter.
     */
    public const ORPHAN_PARENT_STATUSES = ['closed', 'cancelled', 'failed'];

    public Position $position;

    public function __construct(int $positionId)
    {
        $this->position = Position::findOrFail($positionId);
    }

    public function relatable(): Position
    {
        return $this->position;
    }

    public function compute(): array
    {
        $this->position->refresh();

        if (! $this->isOrphanParent()) {
            return [
                'position_id' => $this->position->id,
                'status' => $this->position->status,
                'message' => 'Skipped — position transitioned out of orphan-parent status (closed/cancelled/failed).',
            ];
        }

        $childBlockUuid = $this->step->makeItAParent();

        $this->dispatchCancelLifecycle($childBlockUuid);

        return [
            'position_id' => $this->position->id,
            'message' => 'Cancel-orphan-orders lifecycle initiated',
        ];
    }

    /**
     * True when the wrapped position is in a status that legitimately
     * carries orphan orders the spotter wants cleaned up. Public so
     * the unit suite can assert the guard without invoking compute().
     */
    public function isOrphanParent(): bool
    {
        return in_array($this->position->status, self::ORPHAN_PARENT_STATUSES, true);
    }

    /**
     * Hand off to the existing CancelPositionOpenOrders lifecycle.
     * Public so unit tests can drive it directly with a synthesised
     * blockUuid, mirroring the testing seam used by PrepareOrderCorrectionJob.
     */
    public function dispatchCancelLifecycle(string $blockUuid): int
    {
        $resolver = JobProxy::with($this->position->account);
        $lifecycleClass = $resolver->resolve(CancelPositionOpenOrdersLifecycle::class);
        $lifecycle = new $lifecycleClass($this->position);

        return $lifecycle->dispatch(
            blockUuid: $blockUuid,
            startIndex: 1,
            workflowId: null,
        );
    }
}
