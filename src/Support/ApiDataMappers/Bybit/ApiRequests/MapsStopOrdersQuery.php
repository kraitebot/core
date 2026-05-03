<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Bybit\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\Account;
use Kraite\Core\Support\Math;
use Kraite\Core\Support\ValueObjects\ApiProperties;

/**
 * Maps Bybit stop orders (conditional orders) query.
 *
 * Bybit V5 returns stop orders from the same endpoint as regular orders,
 * but with orderFilter=StopOrder parameter.
 */
trait MapsStopOrdersQuery
{
    public function prepareQueryStopOrdersProperties(Account $account): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $account);
        $properties->set('options.category', 'linear');
        $properties->set('options.settleCoin', 'USDT');
        $properties->set('options.orderFilter', 'StopOrder');

        return $properties;
    }

    /**
     * Resolves Bybit stop orders response.
     *
     * Bybit V5 response structure (same as regular orders):
     * { result: { list: [...orders] } }
     *
     * Stop orders have stopOrderType field populated (e.g., 'StopLoss', 'TakeProfit').
     */
    public function resolveQueryStopOrdersResponse(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), associative: true);
        $orders = $data['result']['list'] ?? [];

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
     * For stop orders, triggerPrice is the primary display price.
     */
    private function computeStopOrderPrice(array $order): string
    {
        // Primary: triggerPrice (for conditional/stop orders)
        $triggerPrice = $order['triggerPrice'] ?? '0';
        if (Math::gt((string) $triggerPrice, '0')) {
            return (string) $triggerPrice;
        }

        // Fallback to regular price
        $price = $order['price'] ?? '0';
        if (Math::gt((string) $price, '0')) {
            return (string) $price;
        }

        return '0';
    }
}
