<?php

declare(strict_types=1);

namespace Kraite\Core\Trading\OrderLifecycle;

use Closure;
use Illuminate\Support\Facades\DB;
use Kraite\Core\Enums\OrderStatus;
use Kraite\Core\Jobs\Atomic\Position\SyncPositionQuantityFromExchangeJob;
use Kraite\Core\Jobs\Lifecycles\Order\PrepareOrderCorrectionJob;
use Kraite\Core\Jobs\Lifecycles\Position\ApplyWapJob;
use Kraite\Core\Jobs\Lifecycles\Position\ClosePositionJob;
use Kraite\Core\Jobs\Lifecycles\Position\PreparePositionReplacementJob;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\Proxies\JobProxy;
use StepDispatcher\Models\Step;
use StepDispatcher\Support\Steps;

/**
 * Owns the transactional side effects triggered by order lifecycle changes.
 */
final class OrderLifecycleDispatcher
{
    public function dispatchClosePosition(Order $order, Position $position): void
    {
        $this->forLockedActivePosition($position, function (Position $locked) use ($order): void {
            if ($order->type === 'STOP-MARKET') {
                $locked->exchangeSymbol?->applySystemBlock(ExchangeSymbol::SYSTEM_BLOCK_STOP_LOSS_TRIGGERED);
            }

            if (Step::hasLiveWorkflow($locked, ClosePositionJob::class)) {
                return;
            }

            // Acknowledge the live status, including TRIGGERED. Hardcoding
            // FILLED would let triggered algo orders re-enter this lifecycle.
            $order->updateSaving(['reference_status' => $order->status]);
            $locked->updateToClosing();

            Step::create([
                'class' => ClosePositionJob::class,
                'queue' => 'priority',
                'priority' => 'high',
                'relatable_type' => $locked->getMorphClass(),
                'relatable_id' => $locked->getKey(),
                'arguments' => [
                    'positionId' => $locked->id,
                    'message' => "{$order->type} order #{$order->id} filled — closing position",
                ],
            ]);
        });
    }

    public function dispatchPositionReplacement(Order $order, Position $position): void
    {
        // Do not acknowledge reference_status here. Replacement discovers the
        // exact orders needing recreation through status/reference divergence.
        $this->forLockedActivePosition($position, function (Position $locked) use ($order): void {
            if (Step::hasLiveWorkflow($locked, PreparePositionReplacementJob::class)) {
                return;
            }

            $action = match ($order->status) {
                OrderStatus::Expired->value => 'expired',
                OrderStatus::Rejected->value => 'rejected',
                default => 'cancelled',
            };

            Step::create([
                'class' => PreparePositionReplacementJob::class,
                'queue' => 'priority',
                'priority' => 'high',
                'relatable_type' => $locked->getMorphClass(),
                'relatable_id' => $locked->getKey(),
                'arguments' => [
                    'positionId' => $locked->id,
                    'triggerStatus' => $order->status,
                    'message' => "{$order->type} order #{$order->id} {$action} — preparing replacement",
                ],
            ]);
        });
    }

    public function dispatchApplyWap(Order $order, Position $position): void
    {
        if ($position->profitOrder()?->status === OrderStatus::Filled->value) {
            return;
        }

        $this->forLockedActivePosition($position, function (Position $locked) use ($order): void {
            // The opening lifecycle still owns the position and validates every
            // opening order before activation. Starting an independent WAP
            // workflow here would fail its active-to-waping transition and
            // falsely acknowledge the fill before opening resolves it.
            if ($locked->status === 'opening') {
                return;
            }

            if (Step::hasLiveWorkflow($locked, ApplyWapJob::class)) {
                return;
            }

            // Acknowledge only after dedupe succeeds. A fill arriving during an
            // existing WAP must remain detectable on the next synchronization.
            $order->updateSaving(['reference_status' => OrderStatus::Filled->value]);

            Step::create([
                'class' => ApplyWapJob::class,
                'queue' => 'priority',
                'priority' => 'high',
                'relatable_type' => $locked->getMorphClass(),
                'relatable_id' => $locked->getKey(),
                'arguments' => [
                    'positionId' => $locked->id,
                    'message' => "LIMIT order #{$order->id} filled — applying WAP",
                ],
            ]);
        });
    }

    public function dispatchSyncPositionQuantity(Position $position): void
    {
        // reference_status intentionally remains unchanged. Multiple fill
        // chunks arrive as repeated PARTIALLY_FILLED events; acknowledging the
        // first would suppress every later chunk.
        if (! in_array($position->status, $position->activeStatuses(), true)) {
            return;
        }

        $this->forLockedActivePosition($position, function (Position $locked): void {
            if (Step::hasLiveWorkflow($locked, SyncPositionQuantityFromExchangeJob::class)) {
                return;
            }

            Step::create([
                'class' => SyncPositionQuantityFromExchangeJob::class,
                'queue' => 'positions',
                'relatable_type' => $locked->getMorphClass(),
                'relatable_id' => $locked->getKey(),
                'arguments' => [
                    'positionId' => $locked->id,
                ],
            ]);
        });
    }

    public function dispatchOrderCorrection(
        Order $order,
        Position $position,
        bool $hasPriceDrift,
        bool $hasQuantityDrift,
    ): void {
        $this->forLockedActivePosition(
            $position,
            function (Position $locked) use ($order, $position, $hasPriceDrift, $hasQuantityDrift): void {
                // Resolve before dedupe because the stored step uses the
                // exchange-specific class, not necessarily the base class.
                $resolvedClass = JobProxy::with($position->account)
                    ->resolve(PrepareOrderCorrectionJob::class);

                $alreadyPending = Step::query()
                    ->forClasses($resolvedClass)
                    ->whereRaw("JSON_EXTRACT(arguments, '$.orderId') = ?", [$order->id])
                    ->inProgress()
                    ->exists();

                if ($alreadyPending) {
                    return;
                }

                $driftType = match (true) {
                    $hasPriceDrift && $hasQuantityDrift => 'price and quantity',
                    $hasPriceDrift => 'price',
                    default => 'quantity',
                };

                Step::create([
                    'class' => $resolvedClass,
                    'queue' => 'priority',
                    'priority' => 'high',
                    'relatable_type' => $locked->getMorphClass(),
                    'relatable_id' => $locked->getKey(),
                    'arguments' => [
                        'positionId' => $position->id,
                        'orderId' => $order->id,
                        'message' => "{$order->type} order #{$order->id} modified ({$driftType}) — correcting",
                    ],
                ]);
            },
        );
    }

    /**
     * Serializes every lifecycle decision for a position and re-checks its
     * current state after acquiring the row lock. The trading prefix covers
     * both the dedupe query and the step insert.
     *
     * @param  Closure(Position): void  $callback
     */
    private function forLockedActivePosition(Position $position, Closure $callback): void
    {
        Steps::usingPrefix('trading', function () use ($position, $callback): void {
            DB::transaction(function () use ($position, $callback): void {
                $locked = Position::query()
                    ->whereKey($position->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! in_array($locked->status, $locked->activeStatuses(), true)) {
                    return;
                }

                $callback($locked);
            });
        });
    }
}
