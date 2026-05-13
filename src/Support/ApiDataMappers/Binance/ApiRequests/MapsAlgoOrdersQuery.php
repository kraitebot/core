<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Binance\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\Account;
use Kraite\Core\Support\Math;
use Kraite\Core\Support\ValueObjects\ApiProperties;

/**
 * Maps Binance algo orders (stop-market, take-profit, trailing-stop) query.
 *
 * Since December 9, 2025, Binance migrated conditional orders to a new "Algo Order"
 * service. Regular open orders endpoint no longer returns these orders - a separate
 * endpoint (/fapi/v1/openAlgoOrders) must be used.
 */
trait MapsAlgoOrdersQuery
{
    public function prepareQueryAlgoOrdersProperties(Account $account): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $account);

        return $properties;
    }

    /**
     * Resolves Binance algo orders response.
     *
     * Binance /fapi/v1/openAlgoOrders returns a raw array of orders:
     * [
     *     {
     *         "algoId": 4000000047401111,
     *         "clientAlgoId": "stToAg_OTO_554635708_2",
     *         "algoType": "CONDITIONAL",
     *         "orderType": "STOP_MARKET",
     *         "symbol": "SOLUSDT",
     *         "side": "BUY",
     *         "positionSide": "SHORT",
     *         "quantity": "0.18",
     *         "algoStatus": "NEW",
     *         "triggerPrice": "136.0000",
     *         "workingType": "MARK_PRICE",
     *         "reduceOnly": true,
     *         "createTime": 1765796269439,
     *         "updateTime": 1765796269439
     *     }
     * ]
     */
    public function resolveQueryAlgoOrdersResponse(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), associative: true);

        // Binance has shipped both shapes for the open-algo-orders
        // endpoint: a bare array, AND a wrapped `{"orders": [...]}`
        // envelope. The recovery path already handles both
        // (BinancePositionRecoverer::675-677). Pre-fix, this mapper
        // only handled bare and treated the wrapped shape as
        // empty — drift analysis would report no algo orders even
        // when live algo SL/TP existed, so DB algo rows looked
        // missing. An error envelope with `code` is still empty.
        if (! is_array($data) || isset($data['code'])) {
            $orders = [];
        } elseif (isset($data['orders']) && is_array($data['orders'])) {
            $orders = $data['orders'];
        } else {
            $orders = $data;
        }

        return array_map(callback: function (array $order): array {
            $order['_price'] = $this->computeAlgoOrderPrice($order);
            $order['_orderType'] = $this->canonicalAlgoOrderType($order);

            // Mark as algo order for frontend distinction
            $order['order_source'] = 'algo';

            return $order;
        }, array: $orders);
    }

    /**
     * Compute the effective display price for algo orders.
     *
     * For algo orders, triggerPrice is the primary display price.
     * Trailing stop orders may use activatePrice.
     */
    private function computeAlgoOrderPrice(array $order): string
    {
        // Primary: triggerPrice (for CONDITIONAL orders)
        $triggerPrice = $order['triggerPrice'] ?? '0';
        if (Math::gt((string) $triggerPrice, '0')) {
            return (string) $triggerPrice;
        }

        // Fallback: activatePrice (for trailing stop orders)
        $activatePrice = $order['activatePrice'] ?? '0';
        if (Math::gt((string) $activatePrice, '0')) {
            return (string) $activatePrice;
        }

        return '0';
    }

    /**
     * Returns a canonical order type from Binance algo order data.
     *
     * Algo orders use 'algoType' and 'orderType' fields instead of 'type'.
     * - algoType: "CONDITIONAL" (stop-market, take-profit) or "VP" (volume participation)
     * - orderType: "MARKET" or "LIMIT"
     *
     * We derive the canonical type based on combination of algoType + side behavior.
     */
    private function canonicalAlgoOrderType(array $order): string
    {
        $algoType = $order['algoType'] ?? '';
        $orderType = $order['orderType'] ?? '';

        // Volume participation algo
        if ($algoType === 'VP') {
            return $orderType === 'LIMIT' ? 'LIMIT' : 'MARKET';
        }

        // Conditional orders (STOP_MARKET, TAKE_PROFIT_MARKET, etc.)
        if ($algoType === 'CONDITIONAL') {
            // For conditional orders, we label them as STOP_MARKET since
            // that's the most common use case. The frontend can distinguish
            // further using triggerPrice and side if needed.
            return 'STOP_MARKET';
        }

        return 'UNKNOWN';
    }
}
