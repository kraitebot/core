<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Lifecycles\Position;

use Illuminate\Support\Collection;
use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Enums\BitgetAccountMode;
use Kraite\Core\Jobs\Atomic\Order\Bitget\PlacePositionTpslJob;
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
    public function startOrSkip(): bool
    {
        // Position must be in an active status
        if (! in_array($this->position->status, $this->position->activeStatuses(), true)) {
            return false;
        }

        // Find orders that need recreation
        $this->ordersToRecreate = $this->findOrdersNeedingRecreation();

        return $this->ordersToRecreate->isNotEmpty();
    }

    public function compute()
    {
        $resolver = JobProxy::with($this->position->account);
        $unifiedProtectionOrders = $this->unifiedProtectionOrdersToReplace();
        $ordersToRecreateIndividually = $this->ordersToRecreate->except(
            $unifiedProtectionOrders->pluck('id')->all(),
        );

        $this->buildChildChainOnce(function (string $blockUuid) use (
            $ordersToRecreateIndividually,
            $resolver,
            $unifiedProtectionOrders,
        ): void {
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
            foreach ($ordersToRecreateIndividually as $order) {
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

            // Bitget Unified represents full-position TP and SL as one remote
            // strategy. Recreating either local leg independently would split
            // that strategy and can leave the position partly protected. One
            // combined placement replaces both local projections instead.
            if ($unifiedProtectionOrders->isNotEmpty()) {
                Step::create([
                    'class' => PlacePositionTpslJob::class,
                    'queue' => 'positions',
                    'arguments' => [
                        'positionId' => $this->position->id,
                        'replacedOrderIds' => $unifiedProtectionOrders->pluck('id')->all(),
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
        });

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
     * - Status is CANCELLED, EXPIRED, or REJECTED
     * - reference_status differs from status (hasn't been handled yet)
     * - Type is LIMIT, PROFIT-LIMIT, or STOP-MARKET
     *
     * @return Collection<int, Order>
     */
    public function findOrdersNeedingRecreation(): Collection
    {
        return $this->position->orders()
            ->whereIn('status', ['CANCELLED', 'EXPIRED', 'REJECTED'])
            ->where(function ($query): void {
                // reference_status differs from status OR is NULL (never set)
                $query->whereColumn('reference_status', '!=', 'status')
                    ->orWhereNull('reference_status');
            })
            ->whereIn('type', ['LIMIT', 'PROFIT-LIMIT', 'STOP-MARKET'])
            ->get();
    }

    /** @return Collection<int, Order> */
    private function unifiedProtectionOrdersToReplace(): Collection
    {
        if (! $this->isBitgetUnified()) {
            return collect();
        }

        $triggeringOrders = $this->ordersToRecreate
            ->whereIn('type', ['PROFIT-LIMIT', 'STOP-MARKET']);

        if ($triggeringOrders->isEmpty()) {
            return collect();
        }

        $exchangeOrderIds = $triggeringOrders
            ->pluck('exchange_order_id')
            ->filter(static fn (mixed $orderId): bool => is_string($orderId) && $orderId !== '')
            ->unique()
            ->values();

        return $this->position->orders()
            ->whereIn('type', ['PROFIT-LIMIT', 'STOP-MARKET'])
            ->where(function ($query) use ($exchangeOrderIds, $triggeringOrders): void {
                $query->whereIn('id', $triggeringOrders->pluck('id')->all());

                if ($exchangeOrderIds->isNotEmpty()) {
                    $query->orWhereIn('exchange_order_id', $exchangeOrderIds->all());
                }
            })
            ->get();
    }

    private function isBitgetUnified(): bool
    {
        $account = $this->position->account;

        return $account->apiSystem->canonical === 'bitget'
            && $account->resolveBitgetAccountMode() === BitgetAccountMode::Unified;
    }
}
