<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Lifecycles\Order\Bitget;

use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Enums\OrderStatus;
use Kraite\Core\Jobs\Atomic\Order\Bitget\ModifyAlgoOrderJob;
use Kraite\Core\Jobs\Atomic\Order\CorrectModifiedOrderJob;
use Kraite\Core\Jobs\Atomic\Order\SyncPositionOrdersJob;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\Math;
use Kraite\Core\Support\Proxies\JobProxy;
use StepDispatcher\Models\Step;

/**
 * PrepareOrderCorrectionJob (Orchestrator) — Bitget
 *
 * Bitget-specific override of the order correction lifecycle.
 *
 * The base (Binance-shaped) flow uses cancel+recreate for algo orders. That
 * pattern fails for Bitget `pos_profit` / `pos_loss` orders because Bitget's
 * `cancel-plan-order` returns a silent no-op for them (`successList:[],
 * failureList:[]`) — these orders are attached to the position, not stored
 * as standalone plan orders. The only correction path that works is
 * `modify-tpsl-order`.
 *
 * Algo branch: dispatch a single `ModifyAlgoOrderJob` (Bitget-only) +
 * follow-up `SyncPositionOrdersJob` to refresh state.
 *
 * LIMIT branch: identical to base — `apiModify` works on Bitget regular
 * orders the same way it works on Binance.
 *
 * @see \Kraite\Core\Jobs\Lifecycles\Order\PrepareOrderCorrectionJob (base)
 */
final class PrepareOrderCorrectionJob extends BaseQueueableJob
{
    public Position $position;

    public Order $order;

    public ?string $message;

    public function __construct(int $positionId, int $orderId, ?string $message = null)
    {
        $this->position = Position::findOrFail($positionId);
        $this->order = Order::findOrFail($orderId);
        $this->message = $message;
    }

    public function relatable(): Position
    {
        return $this->position;
    }

    public function startOrFail(): bool
    {
        if ($this->order->position_id !== $this->position->id) {
            return false;
        }

        return $this->order->reference_price !== null || $this->order->reference_quantity !== null;
    }

    public function startOrSkip(): bool
    {
        if (! in_array($this->position->status, $this->position->activeStatuses(), true)) {
            return false;
        }

        if (! in_array($this->order->status, OrderStatus::workingValues(), true)) {
            return false;
        }

        return $this->orderIsModified();
    }

    /**
     * @return array<string, mixed>
     */
    public function compute(): array
    {
        $resolver = JobProxy::with($this->position->account);
        $isAlgo = (bool) $this->order->is_algo;
        $response = $isAlgo
            ? [
                'position_id' => $this->position->id,
                'order_id' => $this->order->id,
                'strategy' => 'modify_tpsl',
                'message' => 'Bitget algo order correction initiated via modify-tpsl-order',
            ]
            : [
                'position_id' => $this->position->id,
                'order_id' => $this->order->id,
                'strategy' => 'modify',
                'message' => 'LIMIT order correction initiated via apiModify()',
            ];

        $this->buildChildChainOnce(function (string $blockUuid) use ($resolver, $isAlgo, &$response): void {
            $response = $isAlgo
                ? $this->dispatchModifyAlgoWorkflow($resolver, $blockUuid)
                : $this->dispatchLimitCorrectionWorkflow($resolver, $blockUuid);
        });

        return $response;
    }

    /**
     * Algo correction via modify-tpsl-order (Bitget specificity).
     *
     * @return array<string, mixed>
     */
    public function dispatchModifyAlgoWorkflow(JobProxy $resolver, string $blockUuid): array
    {
        Step::create([
            'class' => $resolver->resolve(ModifyAlgoOrderJob::class),
            'queue' => 'positions',
            'arguments' => [
                'positionId' => $this->position->id,
                'orderId' => $this->order->id,
            ],
            'block_uuid' => $blockUuid,
            'index' => 1,
        ]);

        Step::create([
            'class' => $resolver->resolve(SyncPositionOrdersJob::class),
            'queue' => 'positions',
            'arguments' => [
                'positionId' => $this->position->id,
            ],
            'block_uuid' => $blockUuid,
            'index' => 2,
        ]);

        return [
            'position_id' => $this->position->id,
            'order_id' => $this->order->id,
            'strategy' => 'modify_tpsl',
            'message' => 'Bitget algo order correction initiated via modify-tpsl-order',
        ];
    }

    /**
     * LIMIT correction via apiModify — identical to the base flow.
     *
     * @return array<string, mixed>
     */
    public function dispatchLimitCorrectionWorkflow(JobProxy $resolver, string $blockUuid): array
    {
        Step::create([
            'class' => $resolver->resolve(CorrectModifiedOrderJob::class),
            'queue' => 'positions',
            'arguments' => [
                'positionId' => $this->position->id,
                'orderId' => $this->order->id,
            ],
            'block_uuid' => $blockUuid,
            'index' => 1,
        ]);

        Step::create([
            'class' => $resolver->resolve(SyncPositionOrdersJob::class),
            'queue' => 'positions',
            'arguments' => [
                'positionId' => $this->position->id,
            ],
            'block_uuid' => $blockUuid,
            'index' => 2,
        ]);

        return [
            'position_id' => $this->position->id,
            'order_id' => $this->order->id,
            'strategy' => 'modify',
            'message' => 'LIMIT order correction initiated via apiModify()',
        ];
    }

    public function orderIsModified(): bool
    {
        $hasPriceDrift = $this->order->reference_price !== null
            && ! Math::equal((string) $this->order->price, (string) $this->order->reference_price);

        $hasQuantityDrift = $this->order->reference_quantity !== null
            && ! Math::equal((string) $this->order->quantity, (string) $this->order->reference_quantity);

        return $hasPriceDrift || $hasQuantityDrift;
    }
}
