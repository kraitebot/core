<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Recovery;

use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Throwable;

final class RecoveryOpeningOrderCanceller
{
    public static function cancel(Position $position, RecoveryReport $report): int
    {
        $orders = $position->orders()
            ->where('type', 'LIMIT')
            ->where('is_algo', false)
            ->whereIn('status', ['NEW', 'PARTIALLY_FILLED'])
            ->whereNotNull('exchange_order_id')
            ->get();

        if ($orders->isEmpty()) {
            return 0;
        }

        $account = $position->account;
        $exceptionHandler = BaseExceptionHandler::make($account->apiSystem->canonical)
            ->withAccount($account);
        $cancelled = 0;

        foreach ($orders as $order) {
            $originalReferenceStatus = $order->reference_status;

            Order::withoutEvents(fn () => $order->update([
                'reference_status' => 'CANCELLED',
            ]));

            try {
                RecoveryApiThrottle::call($account, fn () => $order->apiCancel());
            } catch (Throwable $exception) {
                if (! $exceptionHandler->ignoreException($exception)) {
                    Order::withoutEvents(fn () => $order->update([
                        'reference_status' => $originalReferenceStatus,
                    ]));

                    $report->warning(sprintf(
                        'Position #%d: opening order #%d cancellation failed before recovery terminal write — %s',
                        $position->id,
                        $order->id,
                        $exception->getMessage(),
                    ));

                    throw $exception;
                }
            }

            Order::withoutEvents(fn () => $order->update([
                'status' => 'CANCELLED',
                'reference_status' => 'CANCELLED',
            ]));
            $cancelled++;
        }

        $report->line(sprintf(
            '  ✓ Position #%d (%s %s): synchronously cancelled %d opening order(s)',
            $position->id,
            $position->parsed_trading_pair,
            $position->direction,
            $cancelled,
        ));

        return $cancelled;
    }
}
