<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Debug;

use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Jobs\Atomic\Order\PlaceLimitOrderJob;
use Kraite\Core\Models\Order;
use Kraite\Core\Support\Proxies\JobProxy;
use StepDispatcher\Models\Step;

/**
 * DispatchPlaceLimitOrderJob (Debug Orchestrator)
 *
 * Creates a single child step to place a limit order on the exchange.
 * Used for stress-testing the step dispatcher workflow with real API calls.
 */
class DispatchPlaceLimitOrderJob extends BaseQueueableJob
{
    public Order $order;

    public function __construct(int $orderId)
    {
        $this->order = Order::findOrFail($orderId);
    }

    public function relatable()
    {
        return $this->order->position;
    }

    public function compute()
    {
        $resolver = JobProxy::with($this->order->position->account);

        // Single child step: place the limit order on the exchange
        Step::create([
            'class' => $resolver->resolve(PlaceLimitOrderJob::class),
            'arguments' => [
                'orderId' => $this->order->id,
                'rungIndex' => 1,
            ],
            'block_uuid' => $this->uuid(),
            'index' => 1,
        ]);

        return [
            'order_id' => $this->order->id,
            'position_id' => $this->order->position_id,
            'message' => 'Limit order placement dispatched',
        ];
    }
}
