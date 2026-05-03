<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Kucoin\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\Account;
use Kraite\Core\Support\Math;
use Kraite\Core\Support\ValueObjects\ApiProperties;

/**
 * Maps KuCoin stop orders (conditional orders) query.
 *
 * KuCoin Futures has a separate endpoint /api/v1/stopOrders for untriggered stop orders.
 *
 * @see https://www.kucoin.com/docs/rest/futures-trading/orders/get-untriggered-stop-order-list
 */
trait MapsStopOrdersQuery
{
    public function prepareQueryStopOrdersProperties(Account $account): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $account);

        return $properties;
    }

    /**
     * Resolves KuCoin stop orders response.
     *
     * KuCoin Futures response structure:
     * {
     *     "code": "200000",
     *     "data": {
     *         "currentPage": 1,
     *         "pageSize": 50,
     *         "totalNum": 1,
     *         "totalPage": 1,
     *         "items": [
     *             {
     *                 "id": "vs8hoo8os561f5np0032vngj",
     *                 "symbol": "XBTUSDTM",
     *                 "type": "limit",
     *                 "side": "buy",
     *                 "price": "30000",
     *                 "size": 1,
     *                 "stop": "down",
     *                 "stopPrice": "30000",
     *                 "stopPriceType": "TP",
     *                 "leverage": "10",
     *                 "createdAt": 1234567890000,
     *                 ...
     *             }
     *         ]
     *     }
     * }
     */
    public function resolveQueryStopOrdersResponse(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), associative: true);
        $orders = $data['data']['items'] ?? [];

        return array_map(callback: function (array $order): array {
            $order['_price'] = $this->computeStopOrderPrice($order);
            $order['_orderType'] = $this->canonicalOrderType($order);
        
            // Mark as conditional order for frontend distinction
            $order['order_source'] = 'conditional';
        
            return $order;
        }, array: $orders);
    }

    /**
     * Compute the effective display price for stop orders.
     *
     * For stop orders, stopPrice is the primary display price.
     */
    private function computeStopOrderPrice(array $order): string
    {
        // Primary: stopPrice (for conditional/stop orders)
        $stopPrice = $order['stopPrice'] ?? null;
        if ($stopPrice !== null && Math::gt((string) $stopPrice, '0')) {
            return (string) $stopPrice;
        }

        // Fallback to regular price
        $price = $order['price'] ?? '0';
        if (Math::gt((string) $price, '0')) {
            return (string) $price;
        }

        return '0';
    }
}
