<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Bybit\ApiRequests;

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
        $properties->set('options.category', 'linear');
        $properties->set('options.settleCoin', 'USDT');

        return $properties;
    }

    /**
     * Resolves Bybit open orders response.
     *
     * Bybit V5 response structure:
     * { result: { list: [...orders] } }
     */
    public function resolveQueryOpenOrdersResponse(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), associative: true);
        $orders = $data['result']['list'] ?? [];

        return array_map(callback: function (array $order): array {
            $order['_price'] = $this->computeOrderPrice($order);
            $order['_orderType'] = $this->canonicalOrderType($order);
        
            return $order;
        }, array: $orders);
    }

    /**
     * Compute the effective display price based on order type.
     *
     * - Limit: uses price
     * - Market: uses avgPrice (if filled) or 0
     * - Conditional orders (with triggerPrice): uses triggerPrice
     */
    private function computeOrderPrice(array $order): string
    {
        $orderType = $order['orderType'] ?? '';
        $price = $order['price'] ?? '0';
        $triggerPrice = $order['triggerPrice'] ?? '0';
        $avgPrice = $order['avgPrice'] ?? '0';

        // If there's a trigger price set, this is a conditional order
        if (Math::gt((string) $triggerPrice, '0')) {
            return $triggerPrice;
        }

        return match ($orderType) {
            'Limit' => $price,
            'Market' => Math::gt((string) $avgPrice, '0') ? $avgPrice : '0',
            default => Math::gt((string) $price, '0') ? $price : '0',
        };
    }
}
