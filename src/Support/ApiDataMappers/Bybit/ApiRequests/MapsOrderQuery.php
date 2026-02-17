<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Bybit\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\Order;
use Kraite\Core\Support\Math;
use Kraite\Core\Support\ValueObjects\ApiProperties;

trait MapsOrderQuery
{
    /**
     * Prepare properties for querying a single order on Bybit.
     *
     * @see https://bybit-exchange.github.io/docs/v5/order/open-order
     */
    public function prepareOrderQueryProperties(Order $order): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $order);
        $properties->set('options.category', 'linear');
        $properties->set('options.orderId', (string) $order->exchange_order_id);

        // Stop orders (is_algo=true) need orderFilter to be found
        if ($order->is_algo ?? false) {
            $properties->set('options.orderFilter', 'StopOrder');
        }

        return $properties;
    }

    /**
     * Resolve the order query response from Bybit.
     *
     * Bybit V5 response structure (GET /v5/order/realtime):
     * {
     *     "retCode": 0,
     *     "result": {
     *         "list": [{
     *             "orderId": "fd4300ae-7847-404e-b947-b46980a4d140",
     *             "orderLinkId": "test-000005",
     *             "symbol": "ETHUSDT",
     *             "price": "1600.00",
     *             "qty": "0.10",
     *             "side": "Buy",
     *             "orderType": "Limit",
     *             "orderStatus": "New",
     *             "avgPrice": "0",
     *             "cumExecQty": "0",
     *             "cumExecValue": "0",
     *             ...
     *         }]
     *     }
     * }
     */
    public function resolveOrderQueryResponse(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), associative: true);
        $list = $data['result']['list'] ?? [];
        $order = $list[0] ?? [];

        if (empty($order)) {
            return [
                'order_id' => null,
                'status' => 'NOT_FOUND',
                '_raw' => $data,
            ];
        }

        $status = $this->normalizeBybitOrderStatus($order);

        return [
            'order_id' => $order['orderId'] ?? null,
            'symbol' => isset($order['symbol']) ? $this->identifyBaseAndQuote($order['symbol']) : null,
            'status' => $status,
            'price' => $this->computeBybitOrderQueryPrice($order),
            '_price' => $this->computeBybitOrderQueryPrice($order),
            'original_quantity' => (string) ($order['qty'] ?? '0'),
            'executed_quantity' => (string) ($order['cumExecQty'] ?? '0'),
            'type' => $order['orderType'] ?? null,
            '_orderType' => $this->canonicalOrderType($order),
            'side' => isset($order['side']) ? mb_strtoupper($order['side']) : null,
            '_raw' => $order,
        ];
    }

    /**
     * Compute the effective display price for a Bybit order.
     */
    private function computeBybitOrderQueryPrice(array $order): string
    {
        $orderType = $order['orderType'] ?? '';
        $price = (string) ($order['price'] ?? '0');
        $triggerPrice = $order['triggerPrice'] ?? '0';
        $avgPrice = $order['avgPrice'] ?? '0';

        // If there's a trigger price set, this is a conditional order
        if (Math::gt($triggerPrice, 0)) {
            return $triggerPrice;
        }

        return match ($orderType) {
            'Limit' => $price,
            'Market' => Math::gt($avgPrice, 0) ? $avgPrice : '0',
            default => Math::gt($price, 0) ? $price : '0',
        };
    }

    /**
     * Normalize Bybit order status to canonical format.
     */
    private function normalizeBybitOrderStatus(array $order): string
    {
        $status = $order['orderStatus'] ?? '';

        return match ($status) {
            'New', 'Untriggered' => 'NEW',
            'PartiallyFilled', 'PartiallyFilledCanceled' => 'PARTIALLY_FILLED',
            'Filled' => 'FILLED',
            'Cancelled', 'Deactivated', 'Rejected' => 'CANCELLED',
            'Triggered' => 'NEW',
            default => 'UNKNOWN',
        };
    }
}
