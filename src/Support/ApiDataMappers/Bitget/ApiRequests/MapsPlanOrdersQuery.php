<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Bitget\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\Account;
use Kraite\Core\Support\ApiDataMappers\Bitget\BitgetProductContext;
use Kraite\Core\Support\Math;
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
        $properties->set(
            'options.productType',
            BitgetProductContext::fromQuote($account->trading_quote)->productType
        );

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
        $responseData = $data['data'] ?? [];
        $orders = is_array($responseData)
            ? ($responseData['entrustedList'] ?? $responseData['list'] ?? (array_is_list($responseData) ? $responseData : []))
            : [];

        return array_map(callback: function (array $order): array {
            $order = $this->normalizeUnifiedStrategyOrder($order);
            $order['clientOrderId'] ??= $order['clientOid'] ?? null;
            $order['status'] ??= $this->normalizePlanSnapshotStatus((string) ($order['planStatus'] ?? ''));
            // Add _price using triggerPrice for plan orders
            $order['_price'] = $this->computePlanOrderPrice($order);
            $order['_orderType'] = $this->canonicalOrderType($order);

            // Mark as plan order for frontend distinction
            $order['order_source'] = 'plan';

            return $order;
        }, array: $orders);
    }

    private function normalizePlanSnapshotStatus(string $status): string
    {
        return match (mb_strtolower($status)) {
            'live', 'not_trigger', 'pending', 'submitting' => 'NEW',
            'executed', 'triggered', 'success' => 'FILLED',
            'cancelled', 'canceled' => 'CANCELLED',
            'fail', 'failed' => 'REJECTED',
            default => mb_strtoupper($status),
        };
    }

    private function normalizeUnifiedStrategyOrder(array $order): array
    {
        if (! array_key_exists('status', $order)
            && ! array_key_exists('takeProfit', $order)
            && ! array_key_exists('stopLoss', $order)) {
            return $order;
        }

        $hasStopLoss = Math::gt((string) ($order['stopLoss'] ?? '0'), '0');
        $hasTrigger = Math::gt((string) ($order['triggerPrice'] ?? '0'), '0');
        $order['planStatus'] ??= $order['status'] ?? '';
        $order['size'] ??= $order['qty'] ?? '0';
        $order['planType'] ??= $hasTrigger ? 'normal_plan' : ($hasStopLoss ? 'pos_loss' : 'pos_profit');
        $order['stopLossTriggerPrice'] ??= $hasStopLoss ? (string) $order['stopLoss'] : '';
        $order['stopSurplusTriggerPrice'] ??= ! $hasStopLoss && ! $hasTrigger
            ? (string) ($order['takeProfit'] ?? '')
            : '';
        $order['positionSide'] ??= isset($order['posSide'])
            ? mb_strtoupper((string) $order['posSide'])
            : null;

        return $order;
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
        if ($stopLossPrice !== '' && Math::gt((string) $stopLossPrice, '0')) {
            return (string) $stopLossPrice;
        }

        // Check stopSurplusTriggerPrice (take-profit)
        $takeProfitPrice = $order['stopSurplusTriggerPrice'] ?? '';
        if ($takeProfitPrice !== '' && Math::gt((string) $takeProfitPrice, '0')) {
            return (string) $takeProfitPrice;
        }

        // Fallback to triggerPrice (for normal_plan orders)
        $triggerPrice = $order['triggerPrice'] ?? '0';
        if (Math::gt((string) $triggerPrice, '0')) {
            return (string) $triggerPrice;
        }

        return '0';
    }
}
