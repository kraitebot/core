<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Kucoin\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\Order;
use Kraite\Core\Support\Math;
use Kraite\Core\Support\ValueObjects\ApiProperties;

/**
 * Maps KuCoin stop order (conditional order) operations.
 *
 * KuCoin Futures uses a separate endpoint /api/v1/stopOrders for stop orders.
 * These are untriggered conditional orders that execute when trigger price is reached.
 *
 * @see https://www.kucoin.com/docs/rest/futures-trading/orders/get-untriggered-stop-order-list
 */
trait MapsStopOrder
{
    /**
     * Prepare properties for querying a single stop order on KuCoin Futures.
     *
     * @see https://www.kucoin.com/docs/rest/futures-trading/orders/get-details-of-a-single-untriggered-stop-order
     */
    public function prepareStopOrderQueryProperties(Order $order): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $order);
        $properties->set('options.orderId', (string) $order->exchange_order_id);

        return $properties;
    }

    /**
     * Resolve the stop order query response from KuCoin.
     *
     * KuCoin response structure:
     * {
     *     "code": "200000",
     *     "data": {
     *         "id": "vs8hoo8os561f5np0032vngj",
     *         "symbol": "XBTUSDTM",
     *         "type": "limit",
     *         "side": "buy",
     *         "price": "30000",
     *         "size": 1,
     *         "stop": "down",
     *         "stopPrice": "30000",
     *         "stopPriceType": "TP",
     *         "status": "NEW",
     *         "leverage": "10",
     *         "createdAt": 1234567890000,
     *         ...
     *     }
     * }
     */
    public function resolveStopOrderQueryResponse(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), associative: true);
        $order = $data['data'] ?? [];

        if (empty($order)) {
            return [
                'order_id' => null,
                'status' => 'NOT_FOUND',
                '_raw' => $data,
            ];
        }

        $status = $this->normalizeKucoinStopOrderStatus($order);

        return [
            'order_id' => $order['id'] ?? null,
            'symbol' => isset($order['symbol']) ? $this->identifyBaseAndQuote($order['symbol']) : null,
            'status' => $status,
            'price' => $this->computeStopOrderQueryPrice($order),
            '_price' => $this->computeStopOrderQueryPrice($order),
            'original_quantity' => (string) ($order['size'] ?? '0'),
            'executed_quantity' => '0', // Untriggered stop orders have no fills
            'type' => $order['type'] ?? null,
            '_orderType' => 'STOP_MARKET',
            'side' => isset($order['side']) ? mb_strtoupper($order['side']) : null,
            '_isStopOrder' => true,
            '_raw' => $order,
        ];
    }

    /**
     * Prepare properties for canceling a stop order on KuCoin Futures.
     *
     * @see https://www.kucoin.com/docs/rest/futures-trading/orders/cancel-stop-orders
     */
    public function prepareStopOrderCancelProperties(Order $order): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $order);
        $properties->set('options.orderId', (string) $order->exchange_order_id);

        return $properties;
    }

    /**
     * Resolve the stop order cancel response from KuCoin.
     *
     * KuCoin response structure:
     * {
     *     "code": "200000",
     *     "data": {
     *         "cancelledOrderIds": ["5bd6e9286d99522a52e458de"]
     *     }
     * }
     */
    public function resolveStopOrderCancelResponse(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), associative: true);
        $cancelData = $data['data'] ?? [];
        $cancelledIds = $cancelData['cancelledOrderIds'] ?? [];

        return [
            'order_id' => $cancelledIds[0] ?? null,
            'symbol' => null,
            'status' => ! empty($cancelledIds) ? 'CANCELLED' : 'NOT_FOUND',
            'price' => '0',
            '_price' => '0',
            '_isStopOrder' => true,
            '_raw' => $data,
        ];
    }

    /**
     * Compute the effective display price for stop order query.
     */
    private function computeStopOrderQueryPrice(array $order): string
    {
        $stopPrice = $order['stopPrice'] ?? '0';

        if (Math::gt($stopPrice, 0)) {
            return (string) $stopPrice;
        }

        $price = $order['price'] ?? '0';

        return Math::gt($price, 0) ? (string) $price : '0';
    }

    /**
     * Normalize KuCoin stop order status to canonical format.
     */
    private function normalizeKucoinStopOrderStatus(array $order): string
    {
        $status = $order['status'] ?? '';

        // Stop orders in the list are always untriggered (NEW)
        return match (mb_strtolower($status)) {
            'new', 'open', '' => 'NEW',
            'triggered' => 'FILLED', // Triggered means it became a regular order
            'cancelled', 'canceled' => 'CANCELLED',
            default => 'NEW', // Default to NEW for untriggered orders
        };
    }
}
