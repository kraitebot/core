<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Lifecycles\Order;

use Illuminate\Support\Facades\DB;
use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Jobs\Atomic\Position\SyncPositionQuantityFromExchangeJob;
use Kraite\Core\Jobs\Lifecycles\Order\SyncPositionOrdersJob as SyncPositionOrdersLifecycle;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\Proxies\JobProxy;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Dispatched;
use StepDispatcher\States\Pending;
use StepDispatcher\States\Running;

/**
 * PrepareSyncOrdersJob (Orchestrator)
 *
 * Top-level lifecycle orchestrator dispatched by kraite:cron-sync-orders.
 * Creates the atomic SyncPositionOrdersJob as a child step.
 *
 * The atomic sync job updates order statuses from the exchange.
 * The Order Observer detects changes and dispatches independent
 * lifecycle steps (CancelPositionJob / ClosePositionJob) as needed.
 */
final class PrepareSyncOrdersJob extends BaseQueueableJob
{
    public Position $position;

    public function __construct(int $positionId)
    {
        $this->position = Position::findOrFail($positionId);
    }

    public function relatable()
    {
        return $this->position;
    }

    public function compute()
    {
        // Only dispatch the atomic sync when the position is in steady-state
        // 'active'. Other opened statuses (opening, syncing, closing,
        // cancelling, waping) belong to their respective workflows — firing a
        // sync on top of them races with in-flight dispatches. Crucially, this
        // job no longer flips the position to 'syncing'; that transition and
        // its matching flip-back are both owned by SyncPositionOrdersJob
        // (via the framework's complete() hook), so a crash between here
        // and the child step can no longer leave a position wedged in
        // 'syncing'.
        $this->position->refresh();

        if ($this->position->status !== 'active') {
            return [
                'position_id' => $this->position->id,
                'status' => $this->position->status,
                'message' => 'Skipped — position not in active state, another workflow owns it',
            ];
        }

        $resolver = JobProxy::with($this->position->account);
        $lifecycleClass = $resolver->resolve(SyncPositionOrdersLifecycle::class);
        $lifecycle = new $lifecycleClass($this->position);

        // Self-elect to parent mode now that we've decided to spawn a child.
        // The early-return path above (position not 'active') skips this and
        // leaves child_block_uuid null, so the framework lets the step
        // Complete normally as an orphan instead of waiting forever for
        // children that never come.
        $childBlockUuid = $this->step->makeItAParent();

        $lifecycle->dispatch(
            blockUuid: $childBlockUuid,
            startIndex: 1,
            workflowId: null
        );

        $this->dispatchPartialFillQuantitySyncSafetyNet();

        return [
            'position_id' => $this->position->id,
            'message' => 'Sync orders workflow initiated',
        ];
    }

    /**
     * Bitget safety net for the partial-fill quantity sync.
     *
     * The OrderObserver hook already dispatches SyncPositionQuantityFromExchangeJob
     * on every observed PARTIALLY_FILLED transition. That covers Binance: its
     * REST mapper writes `executedQty` into `orders.quantity`, which dirties
     * the row on every chunk and re-fires the observer. Bitget's mapper
     * falls back to `size` (the V2 detail endpoint omits `filledQty`), so
     * the row stays clean across chunks 2..N — the observer never re-fires
     * and the local quantity drifts.
     *
     * This belt-and-suspenders dispatch runs every kraite:cron-sync-orders
     * tick whenever the position has at least one PARTIALLY_FILLED LIMIT,
     * deduped against the same Pending / Dispatched / Running window the
     * observer uses.
     */
    private function dispatchPartialFillQuantitySyncSafetyNet(): void
    {
        $hasPartialLimit = $this->position->orders()
            ->where('type', 'LIMIT')
            ->where('status', 'PARTIALLY_FILLED')
            ->exists();

        if (! $hasPartialLimit) {
            return;
        }

        DB::transaction(function (): void {
            Position::query()->whereKey($this->position->id)->lockForUpdate()->first();

            $alreadyPending = Step::query()
                ->where('class', SyncPositionQuantityFromExchangeJob::class)
                ->whereRaw("JSON_EXTRACT(arguments, '$.positionId') = ?", [$this->position->id])
                ->whereIn('state', [Pending::class, Dispatched::class, Running::class])
                ->exists();

            if ($alreadyPending) {
                return;
            }

            Step::create([
                'class' => SyncPositionQuantityFromExchangeJob::class,
                'queue' => 'positions',
                'arguments' => [
                    'positionId' => $this->position->id,
                ],
            ]);
        });
    }
}
