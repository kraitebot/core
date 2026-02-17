<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\Order;

use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Trading\Engine;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use StepDispatcher\Models\Step;
use Kraite\Core\Support\Proxies\JobProxy;

/**
 * DispatchLimitOrdersJob (Orchestrator)
 *
 * Orchestrator job that:
 * 1. Calculates the limit ladder using Kraite algorithm
 * 2. Creates Order records in the database
 * 3. Creates N parallel steps to place those orders on the exchange
 *
 * Preconditions:
 * - position.status = 'opening'
 * - Market order already filled (position has quantity and opening_price)
 */
class DispatchLimitOrdersJob extends BaseQueueableJob
{
    public Position $position;

    /** @var array<int, Order> */
    public array $limitOrders = [];

    public function __construct(int $positionId)
    {
        $this->position = Position::findOrFail($positionId);
    }

    public function relatable()
    {
        return $this->position;
    }

    /**
     * Verify position is ready for limit orders.
     */
    public function startOrFail(): bool
    {
        // Position must be in an active status (opening, active, syncing, etc.)
        if (! in_array($this->position->status, $this->position->activeStatuses(), true)) {
            return false;
        }

        // Market order must have filled (position has quantity and opening_price)
        if ($this->position->quantity === null || $this->position->opening_price === null) {
            return false;
        }

        return true;
    }

    public function compute()
    {
        $exchangeSymbol = $this->position->exchangeSymbol;
        $direction = $this->position->direction;
        $totalLimitOrders = $this->position->total_limit_orders ?? 4;

        // Use position's opening_price as reference for ladder calculation
        $referencePrice = $this->position->opening_price;

        // Use position's quantity (from market order) as base for ladder quantities
        $marketOrderQty = $this->position->quantity;

        // Determine side from direction
        $side = $direction === 'LONG' ? 'BUY' : 'SELL';

        $resolver = JobProxy::with($this->position->account);

        // 1. Calculate ladder → 2. Create ALL Orders in database
        $this->limitOrders = collect(Engine::calculateLimitOrdersData(
            totalLimitOrders: $totalLimitOrders,
            direction: $direction,
            referencePrice: $referencePrice,
            marketOrderQty: $marketOrderQty,
            exchangeSymbol: $exchangeSymbol,
            limitQuantityMultipliers: $exchangeSymbol->limit_quantity_multipliers,
        ))
            ->map(function (array $rung) use ($side, $direction) {
                return Order::create([
                    'position_id' => $this->position->id,
                    'type' => 'LIMIT',
                    'status' => 'NEW',
                    'side' => $side,
                    'position_side' => $direction,
                    'price' => $rung['price'],
                    'quantity' => $rung['quantity'],
                ]);
            })
            ->all();

        // 3. Create Steps to place orders on exchange (sequential to allow cancellation on failure)
        $blockUuid = $this->uuid();
        collect($this->limitOrders)
            ->each(function (Order $order, int $rungIndex) use ($resolver, $blockUuid) {
                Step::create([
                    'class' => $resolver->resolve(PlaceLimitOrderJob::class),
                    'arguments' => [
                        'orderId' => $order->id,
                        'rungIndex' => $rungIndex + 1,
                    ],
                    'block_uuid' => $blockUuid,
                    'index' => $rungIndex + 1,
                    'workflow_id' => null,
                ]);
            });

        return [
            'position_id' => $this->position->id,
            'total_limit_orders' => count($this->limitOrders),
            'reference_price' => $referencePrice,
            'market_qty' => $marketOrderQty,
            'orders' => collect($this->limitOrders)
                ->map(function (Order $o) {
                    return ['id' => $o->id, 'price' => $o->price, 'quantity' => $o->quantity];
                })
                ->all(),
            'message' => 'Limit orders created and dispatched',
        ];
    }
}
