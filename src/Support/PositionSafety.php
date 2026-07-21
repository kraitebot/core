<?php

declare(strict_types=1);

namespace Kraite\Core\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Kraite\Core\Jobs\Atomic\Position\CancelPositionOpenOrdersJob;
use Kraite\Core\Jobs\Atomic\Position\ConfirmPositionFlatAndCancelOpeningOrdersJob;
use Kraite\Core\Jobs\Lifecycles\Position\ClosePositionJob;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\Proxies\JobProxy;
use StepDispatcher\Models\Step;
use StepDispatcher\Support\Steps;

final class PositionSafety
{
    public static function scheduleFlatConfirmation(Position $position, string $source): bool
    {
        return Steps::usingPrefix('trading', fn (): bool => DB::transaction(function () use ($position, $source): bool {
            $locked = Position::query()->whereKey($position->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, $locked->activeStatuses(), true)) {
                return false;
            }

            $confirmationClass = JobProxy::with($locked->account)
                ->resolve(ConfirmPositionFlatAndCancelOpeningOrdersJob::class);

            if (Step::query()
                ->forRelatable($locked)
                ->forClasses($confirmationClass)
                ->inProgress()
                ->exists()) {
                return false;
            }

            Step::create([
                'class' => $confirmationClass,
                'queue' => 'priority',
                'priority' => 'high',
                'relatable_type' => $locked->getMorphClass(),
                'relatable_id' => $locked->getKey(),
                'arguments' => [
                    'positionId' => $locked->id,
                    'source' => $source,
                ],
                'dispatch_after' => now()->addSeconds(
                    (int) config('kraite.position_safety.flat_confirmation_delay_seconds', 20),
                ),
            ]);

            Log::channel('jobs')->info('[POSITION-SAFETY] flat confirmation scheduled', [
                'position_id' => $locked->id,
                'source' => $source,
            ]);

            return true;
        }));
    }

    public static function dispatchOpeningOrderCancellation(Position $position, string $source): bool
    {
        return Steps::usingPrefix('trading', fn (): bool => DB::transaction(function () use ($position, $source): bool {
            $locked = Position::query()->whereKey($position->id)->lockForUpdate()->firstOrFail();

            if (! self::hasLiveOpeningOrders($locked)) {
                return false;
            }

            $cancellationClass = JobProxy::with($locked->account)
                ->resolve(CancelPositionOpenOrdersJob::class);

            if (Step::query()
                ->forRelatable($locked)
                ->forClasses($cancellationClass)
                ->inProgress()
                ->whereJsonContains('arguments->openingOrdersOnly', true)
                ->exists()) {
                return false;
            }

            Step::create([
                'class' => $cancellationClass,
                'queue' => 'priority',
                'priority' => 'high',
                'relatable_type' => $locked->getMorphClass(),
                'relatable_id' => $locked->getKey(),
                'arguments' => [
                    'positionId' => $locked->id,
                    'openingOrdersOnly' => true,
                ],
            ]);

            Log::channel('jobs')->info('[POSITION-SAFETY] opening-order cancellation dispatched', [
                'position_id' => $locked->id,
                'source' => $source,
            ]);

            return true;
        }));
    }

    public static function dispatchConfirmedFlatClose(Position $position, string $source): bool
    {
        return Steps::usingPrefix('trading', fn (): bool => DB::transaction(function () use ($position, $source): bool {
            $locked = Position::query()->whereKey($position->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, $locked->activeStatuses(), true)) {
                return false;
            }

            $closePositionClass = JobProxy::with($locked->account)
                ->resolve(ClosePositionJob::class);

            if (Step::hasLiveWorkflow($locked, $closePositionClass)) {
                return false;
            }

            $locked->updateToClosing();

            Step::create([
                'class' => $closePositionClass,
                'queue' => 'positions',
                'priority' => 'high',
                'relatable_type' => $locked->getMorphClass(),
                'relatable_id' => $locked->getKey(),
                'arguments' => [
                    'positionId' => $locked->id,
                    'message' => "Position confirmed flat ({$source})",
                    'positionConfirmedFlat' => true,
                ],
            ]);

            Log::channel('jobs')->info('[POSITION-SAFETY] confirmed-flat close dispatched', [
                'position_id' => $locked->id,
                'source' => $source,
            ]);

            return true;
        }));
    }

    private static function hasLiveOpeningOrders(Position $position): bool
    {
        return $position->orders()
            ->where('type', 'LIMIT')
            ->where('is_algo', false)
            ->whereIn('status', ['NEW', 'PARTIALLY_FILLED'])
            ->whereNotNull('exchange_order_id')
            ->exists();
    }
}
