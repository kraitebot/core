<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Lifecycles\Order;

use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Jobs\Atomic\Order\CancelSingleAlgoOrderJob;
use Kraite\Core\Jobs\Atomic\Order\CorrectModifiedOrderJob;
use Kraite\Core\Jobs\Atomic\Order\RecreateCancelledOrderJob;
use Kraite\Core\Jobs\Atomic\Order\SyncPositionOrdersJob;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\Math;
use Kraite\Core\Support\Proxies\JobProxy;
use StepDispatcher\Models\Step;

/**
 * PrepareOrderCorrectionJob (Orchestrator)
 *
 * Dispatched when the OrderObserver detects an order was modified on the exchange
 * (price/quantity differs from reference values). Determines the correction strategy:
 *
 * LIMIT orders (is_algo=false):
 *   → Use apiModify() to restore reference values
 *
 * Algo orders (STOP-MARKET, etc., is_algo=true):
 *   → Cancel the modified order, then recreate with reference values
 *   (Exchanges don't support modifying algo orders)
 *
 * Flow:
 * - LIMIT order:
 *   1. CorrectModifiedOrderJob → apiModify() with reference values
 *   2. SyncPositionOrdersJob → verify correction + update position status
 *
 * - Algo order:
 *   1. Pre-set reference_status to 'CANCELLED' (prevent OrderObserver cascade)
 *   2. Cancel via CancelAlgoOpenOrdersJob (handles exchange-specific cancel)
 *   3. Sync to get CANCELLED status
 *   4. RecreateCancelledOrderJob → create new order with reference values
 *   5. SyncPositionOrdersJob → verify recreation + update position status
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

    /**
     * Guard: Only run if position is active and order needs correction.
     */
    public function startOrFail(): bool
    {
        // Position must be in an active status
        if (! in_array($this->position->status, $this->position->activeStatuses(), true)) {
            return false;
        }

        // Order must belong to this position
        if ($this->order->position_id !== $this->position->id) {
            return false;
        }

        // Order must be active (NEW or PARTIALLY_FILLED)
        if (! in_array($this->order->status, ['NEW', 'PARTIALLY_FILLED'], true)) {
            return false;
        }

        // Must have reference values to restore
        if ($this->order->reference_price === null && $this->order->reference_quantity === null) {
            return false;
        }

        // Must actually be modified
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
                'strategy' => 'cancel_recreate',
                'message' => 'Algo order correction initiated via cancel+recreate',
            ]
            : [
                'position_id' => $this->position->id,
                'order_id' => $this->order->id,
                'strategy' => 'modify',
                'message' => 'LIMIT order correction initiated via apiModify()',
            ];

        $this->buildChildChainOnce(function (string $blockUuid) use ($resolver, $isAlgo, &$response): void {
            $response = $isAlgo
                ? $this->dispatchAlgoCorrectionWorkflow($resolver, $blockUuid)
                : $this->dispatchLimitCorrectionWorkflow($resolver, $blockUuid);
        });

        return $response;
    }

    /**
     * Dispatch workflow for LIMIT order correction via apiModify().
     *
     * @return array<string, mixed>
     */
    private function dispatchLimitCorrectionWorkflow(JobProxy $resolver, string $blockUuid): array
    {
        // Step 1: Correct the order via apiModify()
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

        // Step 2: Sync all orders to verify and update position status
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

    /**
     * Dispatch workflow for algo order correction via cancel+recreate.
     *
     * @return array<string, mixed>
     */
    private function dispatchAlgoCorrectionWorkflow(JobProxy $resolver, string $blockUuid): array
    {
        // Pre-set reference_status to CANCELLED to prevent OrderObserver
        // from triggering a replacement workflow when the cancel is synced
        $this->order->updateSaving(['reference_status' => 'CANCELLED']);

        // Step 1: Cancel the algo order
        // We use single-order cancel by calling apiCancel() directly on the order
        // via a custom atomic job that handles just this order
        Step::create([
            'class' => $resolver->resolve(CancelSingleAlgoOrderJob::class),
            'queue' => 'positions',
            'arguments' => [
                'positionId' => $this->position->id,
                'orderId' => $this->order->id,
            ],
            'block_uuid' => $blockUuid,
            'index' => 1,
        ]);

        // Step 2: Sync to get CANCELLED status
        Step::create([
            'class' => $resolver->resolve(SyncPositionOrdersJob::class),
            'queue' => 'positions',
            'arguments' => [
                'positionId' => $this->position->id,
            ],
            'block_uuid' => $blockUuid,
            'index' => 2,
        ]);

        // Step 3: Recreate the order with reference values
        Step::create([
            'class' => $resolver->resolve(RecreateCancelledOrderJob::class),
            'queue' => 'positions',
            'arguments' => [
                'positionId' => $this->position->id,
                'orderId' => $this->order->id,
            ],
            'block_uuid' => $blockUuid,
            'index' => 3,
        ]);

        // Step 4: Final sync to verify recreation + update position status
        Step::create([
            'class' => $resolver->resolve(SyncPositionOrdersJob::class),
            'queue' => 'positions',
            'arguments' => [
                'positionId' => $this->position->id,
            ],
            'block_uuid' => $blockUuid,
            'index' => 4,
        ]);

        return [
            'position_id' => $this->position->id,
            'order_id' => $this->order->id,
            'strategy' => 'cancel_recreate',
            'message' => 'Algo order correction initiated via cancel+recreate',
        ];
    }

    /**
     * Check if order price/quantity differs from reference values.
     */
    private function orderIsModified(): bool
    {
        // Match the OrderObserver's drift comparison (Math::equal).
        // The Order::price accessor strips trailing zeros while
        // reference_price returns the raw DECIMAL(20,8) string, so a
        // strict `!==` produces false-drift on numerically equal pairs
        // ('1.4769' vs '1.47690000'). Math::equal collapses both into
        // a precision-aware comparison aligned with the observer.
        $hasPriceDrift = $this->order->reference_price !== null
            && $this->order->price !== null
            && ! Math::equal($this->order->price, $this->order->reference_price);

        $hasQuantityDrift = $this->order->reference_quantity !== null
            && $this->order->quantity !== null
            && ! Math::equal($this->order->quantity, $this->order->reference_quantity);

        return $hasPriceDrift || $hasQuantityDrift;
    }
}
