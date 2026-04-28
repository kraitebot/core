<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\Position;

use Kraite\Core\Abstracts\BaseApiableJob;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Models\Position;
use Throwable;

/**
 * CancelAlgoOpenOrdersJob (Atomic)
 *
 * Cancels all active algo orders (stop-loss, etc.) for a position.
 * These orders use exchange-specific endpoints (Binance Algo API, KuCoin Stop Orders,
 * Bitget Plan Orders) that are NOT covered by the bulk cancel-all-open-orders endpoint.
 *
 * Each algo order is cancelled individually via Order::apiCancel() which routes
 * to the correct exchange-specific cancel method based on the canonical.
 */
final class CancelAlgoOpenOrdersJob extends BaseApiableJob
{
    private const array INACTIVE_STATUSES = ['FILLED', 'CANCELLED', 'EXPIRED'];

    public Position $position;

    public function __construct(int $positionId)
    {
        $this->position = Position::findOrFail($positionId);
    }

    public function assignExceptionHandler(): void
    {
        $canonical = $this->position->account->apiSystem->canonical;
        $this->exceptionHandler = BaseExceptionHandler::make($canonical)
            ->withAccount($this->position->account);
    }

    public function relatable()
    {
        return $this->position;
    }

    public function computeApiable()
    {
        // Ghost guard: an algo order row without an exchange_order_id was
        // never placed on the exchange (typically: PlaceStopLossOrderJob
        // created the local row, then apiPlace() failed — LAB #121 hit
        // this path). Cancelling it via apiCancel() would throw
        // "options.algo id field is required" and mask the real
        // upstream error in the step log. These rows are dropped from
        // the cancellation query; they have nothing to cancel remotely.
        $algoOrders = $this->position->orders()
            ->where('is_algo', true)
            ->whereNotIn('status', self::INACTIVE_STATUSES)
            ->whereNotNull('exchange_order_id')
            ->get();

        // Pre-update reference_status to 'CANCELLED' on algo orders.
        // This prevents the OrderObserver from triggering replacement workflows
        // when these orders are synced after being cancelled.
        if ($algoOrders->isNotEmpty()) {
            $this->position->orders()
                ->where('is_algo', true)
                ->whereNotIn('status', self::INACTIVE_STATUSES)
                ->whereNotNull('exchange_order_id')
                ->update(['reference_status' => 'CANCELLED']);
        }

        $cancelled = [];
        $idempotent = [];

        foreach ($algoOrders as $order) {
            try {
                $apiResponse = $order->apiCancel();
                $cancelled[] = [
                    'order_id' => $order->id,
                    'type' => $order->type,
                    'result' => $apiResponse->result,
                ];
            } catch (Throwable $e) {
                // The exchange may have already cancelled / forgotten the
                // order between the spotter's read and now (Binance
                // -2011 "Unknown order sent" is the canonical case).
                // Per-exchange handlers classify those as ignorable; we
                // mark the row CANCELLED locally and move to the next
                // order so a single stale row doesn't kill the rest of
                // the batch. Anything non-ignorable bubbles up so the
                // framework's normal retry / fail path runs.
                if ($this->exceptionHandler->ignoreException($e)) {
                    $order->updateSaving(['status' => 'CANCELLED']);
                    $idempotent[] = [
                        'order_id' => $order->id,
                        'type' => $order->type,
                        'reason' => 'Order already gone on exchange; DB reconciled.',
                    ];

                    continue;
                }

                throw $e;
            }
        }

        $totalProcessed = count($cancelled) + count($idempotent);

        return [
            'position_id' => $this->position->id,
            'cancelled_count' => count($cancelled),
            'idempotent_count' => count($idempotent),
            'cancelled' => $cancelled,
            'idempotent' => $idempotent,
            'message' => $totalProcessed > 0
                ? 'Algo orders cancelled'
                : 'No active algo orders to cancel',
        ];
    }
}
