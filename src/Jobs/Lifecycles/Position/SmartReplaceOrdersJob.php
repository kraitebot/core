<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Lifecycles\Position;

use Illuminate\Support\Collection;
use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Jobs\Atomic\Order\CancelOrphanAlgoOrdersJob;
use Kraite\Core\Jobs\Atomic\Order\RecreateCancelledOrderJob;
use Kraite\Core\Jobs\Atomic\Order\SyncPositionOrdersJob;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\Proxies\JobProxy;
use StepDispatcher\Models\Step;

/**
 * SmartReplaceOrdersJob (Orchestrator)
 *
 * Smart order replacement - only recreates orders that actually need it.
 *
 * Flow:
 * 1. Query orders that need recreation (CANCELLED/EXPIRED with reference_status mismatch)
 * 2. For each order, dispatch RecreateCancelledOrderJob
 * 3. Dispatch SyncPositionOrdersJob at the end to update position status
 *
 * This is a lightweight alternative to ReplacePositionOrdersJob which does full replacement.
 */
final class SmartReplaceOrdersJob extends BaseQueueableJob
{
    public Position $position;

    /** @var Collection<int, Order> */
    public Collection $ordersToRecreate;

    public function __construct(int $positionId)
    {
        $this->position = Position::findOrFail($positionId);
        $this->ordersToRecreate = collect();
    }

    public function relatable()
    {
        return $this->position;
    }

    /**
     * Verify position is ready for smart replacement.
     */
    public function startOrFail(): bool
    {
        // Position must be in an active status
        if (! in_array($this->position->status, $this->position->activeStatuses(), true)) {
            return false;
        }

        // Find orders that need recreation
        $this->ordersToRecreate = $this->findOrdersNeedingRecreation();

        // Nothing to do if no orders need recreation
        if ($this->ordersToRecreate->isEmpty()) {
            return false;
        }

        return true;
    }

    public function compute()
    {
        $resolver = JobProxy::with($this->position->account);
        $blockUuid = $this->step->child_block_uuid ?? $this->step->makeItAParent();
        $index = 1;

        // Step 1: Scrub orphan algo orders on the exchange for this
        // symbol. On exchanges where the UI's "modify" on algo orders
        // actually cancels+recreates (Binance), the user's moved stop
        // lands as a ghost we don't know about. Without this step, our
        // recreation below lives alongside the ghost and both stops
        // trigger. Exchanges whose modify is in-place resolve to the
        // base no-op so this step completes immediately.
        Step::create([
            'class' => $resolver->resolve(CancelOrphanAlgoOrdersJob::class),
            'queue' => 'positions',
            'arguments' => [
                'positionId' => $this->position->id,
            ],
            'block_uuid' => $blockUuid,
            'index' => $index++,
        ]);

        // Recreate each cancelled order
        foreach ($this->ordersToRecreate as $order) {
            Step::create([
                'class' => $resolver->resolve(RecreateCancelledOrderJob::class),
                'queue' => 'positions',
                'arguments' => [
                    'positionId' => $this->position->id,
                    'orderId' => $order->id,
                ],
                'block_uuid' => $blockUuid,
                'index' => $index++,
            ]);
        }

        // Final step: Sync position orders to update status
        Step::create([
            'class' => $resolver->resolve(SyncPositionOrdersJob::class),
            'queue' => 'positions',
            'arguments' => [
                'positionId' => $this->position->id,
            ],
            'block_uuid' => $blockUuid,
            'index' => $index,
        ]);

        return [
            'position_id' => $this->position->id,
            'orders_to_recreate' => $this->ordersToRecreate->pluck('id')->all(),
            'total_orders' => $this->ordersToRecreate->count(),
            'message' => 'Smart order replacement initiated',
        ];
    }

    /**
     * Find orders that need recreation.
     *
     * An order needs recreation if:
     * - Status is CANCELLED or EXPIRED
     * - reference_status differs from status (hasn't been handled yet)
     * - Type is LIMIT, PROFIT-LIMIT, or STOP-MARKET
     *
     * @return Collection<int, Order>
     */
    public function findOrdersNeedingRecreation(): Collection
    {
        return $this->position->orders()
            ->whereIn('status', ['CANCELLED', 'EXPIRED'])
            ->where(function ($query): void {
                // reference_status differs from status OR is NULL (never set)
                $query->whereColumn('reference_status', '!=', 'status')
                    ->orWhereNull('reference_status');
            })
            ->whereIn('type', ['LIMIT', 'PROFIT-LIMIT', 'STOP-MARKET'])
            ->get();
    }
}
