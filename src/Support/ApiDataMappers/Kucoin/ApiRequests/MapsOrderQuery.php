<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Kucoin\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\Order;
use Kraite\Core\Support\Math;
use Kraite\Core\Support\ValueObjects\ApiProperties;

trait MapsOrderQuery
{
    /**
     * Prepare properties for querying a single order on KuCoin Futures.
     *
     * @see https://www.kucoin.com/docs/rest/futures-trading/orders/get-order-details-by-orderid-clientoid
     */
    public function prepareOrderQueryProperties(Order $order): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $order);
        $properties->set('options.orderId', (string) $order->exchange_order_id);

        return $properties;
    }

    /**
     * Resolve the order query response from KuCoin.
     *
     * KuCoin response structure:
     * {
     *     "code": "200000",
     *     "data": {
     *         "id": "5cdfc138b21023a909e5ad55",
     *         "symbol": "XBTUSDTM",
     *         "type": "limit",
     *         "side": "buy",
     *         "price": "3600",
     *         "size": 20,
     *         "value": "56.568",
     *         "dealValue": "50.0",
     *         "dealSize": 15,
     *         "stp": "",
     *         "stop": "",
     *         "stopPriceType": "",
     *         "stopTriggered": false,
     *         "stopPrice": null,
     *         "timeInForce": "GTC",
     *         "postOnly": false,
     *         "hidden": false,
     *         "iceberg": false,
     *         "leverage": "20",
     *         "forceHold": false,
     *         "closeOrder": false,
     *         "visibleSize": null,
     *         "clientOid": "5ce24c16b210233c36ee321d",
     *         "remark": null,
     *         "tags": null,
     *         "isActive": true,
     *         "cancelExist": false,
     *         "createdAt": 1558167872000,
     *         "updatedAt": 1558167872000,
     *         "endAt": null,
     *         "orderTime": 1558167872000000000,
     *         "settleCurrency": "USDT",
     *         "status": "open",
     *         "filledSize": 15,
     *         "filledValue": "50.0",
     *         "reduceOnly": false
     *     }
     * }
     */
    public function resolveOrderQueryResponse(Response $response): array
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

        $status = $this->normalizeKucoinOrderStatus($order);

        return [
            'order_id' => $order['id'] ?? null,
            'symbol' => isset($order['symbol']) ? $this->identifyBaseAndQuote($order['symbol']) : null,
            'status' => $status,
            'price' => $this->computeKucoinOrderQueryPrice($order),
            '_price' => $this->computeKucoinOrderQueryPrice($order),
            'original_quantity' => (string) ($order['size'] ?? '0'),
            'executed_quantity' => (string) ($order['filledSize'] ?? '0'),
            'type' => $order['type'] ?? null,
            '_orderType' => $this->canonicalOrderType($order),
            'side' => isset($order['side']) ? mb_strtoupper($order['side']) : null,
            '_raw' => $order,
        ];
    }

    /**
     * Compute the effective display price for a KuCoin order.
     */
    private function computeKucoinOrderQueryPrice(array $order): string
    {
        $type = $order['type'] ?? '';
        $price = (string) ($order['price'] ?? '0');
        $stopPrice = $order['stopPrice'] ?? '0';
        $stop = $order['stop'] ?? '';

        // If this is a stop order
        if ($stop !== '' || Math::gt($stopPrice, 0)) {
            return Math::gt($stopPrice, 0) ? (string) $stopPrice : $price;
        }

        return match ($type) {
            'limit' => $price,
            'market' => '0',
            default => Math::gt($price, 0) ? $price : '0',
        };
    }

    /**
     * Normalize KuCoin order status to canonical format.
     */
    private function normalizeKucoinOrderStatus(array $order): string
    {
        $status = $order['status'] ?? '';
        $isActive = $order['isActive'] ?? false;
        $filledSize = $order['filledSize'] ?? '0';
        $size = $order['size'] ?? '0';

        // Check if fully filled
        if (Math::gte($filledSize, $size) && Math::gt($size, 0)) {
            return 'FILLED';
        }

        // Check if partially filled
        if (Math::gt($filledSize, 0) && Math::lt($filledSize, $size)) {
            return 'PARTIALLY_FILLED';
        }

        // Map status values
        return match (mb_strtolower($status)) {
            'open', 'active' => 'NEW',
            'done' => Math::gt($filledSize, 0) ? 'FILLED' : 'CANCELLED',
            'match' => 'PARTIALLY_FILLED',
            default => $isActive ? 'NEW' : 'CANCELLED',
        };
    }
}
