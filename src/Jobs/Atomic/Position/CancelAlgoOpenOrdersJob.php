<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\Position;

use Kraite\Core\Abstracts\BaseApiableJob;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Models\Position;

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
        $algoOrders = $this->position->orders()
            ->where('is_algo', true)
            ->whereNotIn('status', self::INACTIVE_STATUSES)
            ->get();

        // Pre-update reference_status to 'CANCELLED' on algo orders.
        // This prevents the OrderObserver from triggering replacement workflows
        // when these orders are synced after being cancelled.
        if ($algoOrders->isNotEmpty()) {
            $this->position->orders()
                ->where('is_algo', true)
                ->whereNotIn('status', self::INACTIVE_STATUSES)
                ->update(['reference_status' => 'CANCELLED']);
        }

        $cancelled = [];

        foreach ($algoOrders as $order) {
            $apiResponse = $order->apiCancel();
            $cancelled[] = [
                'order_id' => $order->id,
                'type' => $order->type,
                'result' => $apiResponse->result,
            ];
        }

        return [
            'position_id' => $this->position->id,
            'cancelled_count' => count($cancelled),
            'cancelled' => $cancelled,
            'message' => count($cancelled) > 0
                ? 'Algo orders cancelled'
                : 'No active algo orders to cancel',
        ];
    }
}
