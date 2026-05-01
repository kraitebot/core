<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Bitget\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\Account;
use Kraite\Core\Support\ValueObjects\ApiProperties;

/**
 * Maps BitGet plan orders (stop-loss, take-profit, trigger orders) query.
 *
 * Plan orders are conditional orders that execute when a trigger price is reached.
 * They are separate from regular limit/market orders and use a different endpoint.
 */
trait MapsPlanOrdersQuery
{
    public function prepareQueryPlanOrdersProperties(Account $account): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $account);

        // BitGet V2 requires productType for futures
        $properties->set('options.productType', 'USDT-FUTURES');

        // planType=profit_loss returns all TPSL orders (stop-loss, take-profit)
        $properties->set('options.planType', 'profit_loss');

        return $properties;
    }

    /**
     * Resolves BitGet plan orders response.
     *
     * BitGet V2 response structure for plan orders:
     * {
     *     "code": "00000",
     *     "msg": "success",
     *     "requestTime": 1627116936176,
     *     "data": {
     *         "entrustedList": [
     *             {
     *                 "planType": "normal_plan",
     *                 "symbol": "BTCUSDT",
     *                 "size": "0.001",
     *                 "orderId": "1234567890",
     *                 "clientOid": "xxx",
     *                 "triggerPrice": "45000",
     *                 "triggerType": "mark_price",
     *                 "executePrice": "0",
     *                 "planStatus": "live",
     *                 "side": "buy",
     *                 "posSide": "long",
     *                 "marginCoin": "USDT",
     *                 "orderType": "market",
     *                 "enterPointSource": "API",
     *                 "tradeSide": "close",
     *                 "cTime": "1627116936176",
     *                 "uTime": "1627116936176"
     *             }
     *         ],
     *         "endId": "1234567890"
     *     }
     * }
     */
    public function resolveQueryPlanOrdersResponse(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), associative: true);
        $orders = $data['data']['entrustedList'] ?? [];

        return array_map(callback: function (array $order): array {
            // Add _price using triggerPrice for plan orders
            $order['_price'] = $this->computePlanOrderPrice($order);
            $order['_orderType'] = $this->canonicalOrderType($order);

            // Mark as plan order for frontend distinction
            $order['order_source'] = 'plan';

            return $order;
        }, array: $orders);
    }

    /**
     * Compute the effective display price for plan orders.
     *
     * For plan orders, the trigger price is the primary display price.
     * TPSL orders may use stopLossTriggerPrice or stopSurplusTriggerPrice fields.
     */
    private function computePlanOrderPrice(array $order): string
    {
        // Check stopLossTriggerPrice first (for TPSL orders)
        $stopLossPrice = $order['stopLossTriggerPrice'] ?? '';
        if ($stopLossPrice !== '' && (float) $stopLossPrice > 0) {
            return (string) $stopLossPrice;
        }

        // Check stopSurplusTriggerPrice (take-profit)
        $takeProfitPrice = $order['stopSurplusTriggerPrice'] ?? '';
        if ($takeProfitPrice !== '' && (float) $takeProfitPrice > 0) {
            return (string) $takeProfitPrice;
        }

        // Fallback to triggerPrice (for normal_plan orders)
        $triggerPrice = $order['triggerPrice'] ?? '0';
        if ((float) $triggerPrice > 0) {
            return (string) $triggerPrice;
        }

        return '0';
    }
}
