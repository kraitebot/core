<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\Position;

use Kraite\Core\Abstracts\BaseApiableJob;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Models\Position;
use Throwable;

/**
 * CancelPositionOpenOrdersJob (Atomic)
 *
 * Cancels all open orders for a position on the exchange.
 *
 * For most exchanges: Uses Position::apiCancelOpenOrders() which calls
 * the exchange's cancel-all-orders endpoint with symbol filter.
 *
 * For BitGet: Cancels orders individually because BitGet's cancel-all-orders
 * endpoint ignores the symbol parameter and cancels ALL orders on the account.
 */
final class CancelPositionOpenOrdersJob extends BaseApiableJob
{
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
        // Pre-update reference_status to 'CANCELLED' on all syncable orders.
        // This prevents the OrderObserver from triggering replacement workflows
        // when these orders are synced after being cancelled.
        $this->position->orders()
            ->syncable()
            ->update(['reference_status' => 'CANCELLED']);

        // BitGet's cancel-all-orders endpoint ignores the symbol parameter
        // and cancels ALL orders on the account. We must cancel individually.
        if ($this->position->account->apiSystem->canonical === 'bitget') {
            return $this->cancelOrdersIndividually();
        }

        $apiResponse = $this->position->apiCancelOpenOrders();

        return [
            'position_id' => $this->position->id,
            'symbol' => $this->position->parsed_trading_pair,
            'result' => $apiResponse->result,
            'message' => 'Open orders cancelled',
        ];
    }

    /**
     * Cancel orders individually for BitGet.
     *
     * BitGet's cancel-all-orders endpoint has a bug where it ignores the
     * symbol parameter and cancels ALL orders on the account. This method
     * iterates through the position's open orders and cancels each one
     * using the individual cancel-order endpoint.
     *
     * @return array<string, mixed>
     */
    private function cancelOrdersIndividually(): array
    {
        $cancelledOrders = [];
        $failedOrders = [];

        // Get all cancellable orders for this position (LIMIT orders only, not algo)
        $orders = $this->position->orders()
            ->whereIn('status', ['NEW', 'PARTIALLY_FILLED'])
            ->where('is_algo', false)
            ->get();

        foreach ($orders as $order) {
            try {
                $apiResponse = $order->apiCancel();
                $cancelledOrders[] = [
                    'order_id' => $order->id,
                    'exchange_order_id' => $order->exchange_order_id,
                    'type' => $order->type,
                    'result' => $apiResponse->result,
                ];
            } catch (Throwable $e) {
                $failedOrders[] = [
                    'order_id' => $order->id,
                    'exchange_order_id' => $order->exchange_order_id,
                    'type' => $order->type,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'position_id' => $this->position->id,
            'symbol' => $this->position->parsed_trading_pair,
            'cancelled_count' => count($cancelledOrders),
            'failed_count' => count($failedOrders),
            'cancelled_orders' => $cancelledOrders,
            'failed_orders' => $failedOrders,
            'message' => 'Open orders cancelled individually (BitGet)',
        ];
    }
}
