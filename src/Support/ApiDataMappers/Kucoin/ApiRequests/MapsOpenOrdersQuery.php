<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Kucoin\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\Account;
use Kraite\Core\Support\Math;
use Kraite\Core\Support\ValueObjects\ApiProperties;

trait MapsOpenOrdersQuery
{
    public function prepareQueryOpenOrdersProperties(Account $account): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $account);

        // Filter for active orders only
        $properties->set('options.status', 'active');

        return $properties;
    }

    /**
     * Resolves KuCoin open orders response.
     *
     * KuCoin Futures response structure:
     * {
     *     "code": "200000",
     *     "data": {
     *         "currentPage": 1,
     *         "pageSize": 100,
     *         "totalNum": 1,
     *         "totalPage": 1,
     *         "items": [
     *             {
     *                 "id": "5cdfc138b21023a909e5ad55",
     *                 "symbol": "XBTUSDTM",
     *                 "type": "limit",
     *                 "side": "buy",
     *                 "price": "3600",
     *                 "size": 20,
     *                 "value": "56.568",
     *                 "dealValue": "0",
     *                 "dealSize": 0,
     *                 "stp": "",
     *                 "stop": "",
     *                 "stopPriceType": "",
     *                 "stopTriggered": false,
     *                 "stopPrice": null,
     *                 "timeInForce": "GTC",
     *                 "postOnly": false,
     *                 "hidden": false,
     *                 "iceberg": false,
     *                 "leverage": "20",
     *                 "forceHold": false,
     *                 "closeOrder": false,
     *                 "visibleSize": null,
     *                 "clientOid": "5ce24c16b210233c36ee321d",
     *                 "remark": null,
     *                 "tags": null,
     *                 "isActive": true,
     *                 "cancelExist": false,
     *                 "createdAt": 1558167872000,
     *                 "updatedAt": 1558167872000,
     *                 "endAt": null,
     *                 "orderTime": 1558167872000000000,
     *                 "settleCurrency": "USDT",
     *                 "status": "open",
     *                 "filledSize": 0,
     *                 "filledValue": "0",
     *                 "reduceOnly": false
     *             }
     *         ]
     *     }
     * }
     */
    public function resolveQueryOpenOrdersResponse(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), associative: true);
        $orders = $data['data']['items'] ?? [];

        return array_map(callback: function (array $order): array {
            $order['_price'] = $this->computeOrderPrice($order);
            $order['_orderType'] = $this->canonicalOrderType($order);
        
            return $order;
        }, array: $orders);
    }

    /**
     * Compute the effective display price based on order type.
     *
     * - limit: uses price
     * - market: uses 0 (market orders don't have a set price)
     * - stop orders (stop field not empty or stopPrice set): uses stopPrice
     */
    private function computeOrderPrice(array $order): string
    {
        $type = $order['type'] ?? '';
        $price = (string) ($order['price'] ?? '0');
        $stopPrice = $order['stopPrice'] ?? null;
        $stop = $order['stop'] ?? '';

        // If this is a stop order (stop field is set or stopPrice exists)
        if ($stop !== '' || ($stopPrice !== null && Math::gt((string) $stopPrice, '0'))) {
            return (string) ($stopPrice ?? $price);
        }

        return match ($type) {
            'limit' => $price,
            'market' => '0',
            default => Math::gt($price, '0') ? $price : '0',
        };
    }
}
