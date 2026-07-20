<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Bitget\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\Account;
use Kraite\Core\Support\ApiDataMappers\Bitget\BitgetProductContext;
use Kraite\Core\Support\Math;
use Kraite\Core\Support\ValueObjects\ApiProperties;

trait MapsOpenOrdersQuery
{
    public function prepareQueryOpenOrdersProperties(Account $account): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $account);

        $properties->set(
            'options.productType',
            BitgetProductContext::fromQuote($account->trading_quote)->productType
        );

        return $properties;
    }

    /**
     * Resolves BitGet open orders response.
     *
     * BitGet V2 response structure:
     * {
     *     "code": "00000",
     *     "msg": "success",
     *     "requestTime": 1627116936176,
     *     "data": {
     *         "entrustedList": [
     *             {
     *                 "symbol": "BTCUSDT",
     *                 "size": "0.001",
     *                 "orderId": "1234567890",
     *                 "clientOid": "xxx",
     *                 "filledQty": "0",
     *                 "priceAvg": "0",
     *                 "fee": "0",
     *                 "price": "40000",
     *                 "state": "new",
     *                 "side": "buy",
     *                 "force": "gtc",
     *                 "totalProfits": "0",
     *                 "posSide": "long",
     *                 "marginCoin": "USDT",
     *                 "presetStopSurplusPrice": "",
     *                 "presetStopLossPrice": "",
     *                 "quoteSize": "40",
     *                 "orderType": "limit",
     *                 "leverage": "10",
     *                 "marginMode": "crossed",
     *                 "reduceOnly": "NO",
     *                 "enterPointSource": "API",
     *                 "tradeSide": "open",
     *                 "cTime": "1627116936176",
     *                 "uTime": "1627116936176"
     *             }
     *         ],
     *         "endId": "1234567890"
     *     }
     * }
     */
    public function resolveQueryOpenOrdersResponse(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), associative: true);

        // Classic (v2) nests orders under `entrustedList`; unified (v3)
        // nests them under `list`. Field names of each order are close
        // enough that the shared price/type enrichment applies to both.
        $orders = $data['data']['entrustedList'] ?? $data['data']['list'] ?? [];

        return array_map(callback: function (array $order): array {
            $order['state'] ??= $order['orderStatus'] ?? '';
            $order['size'] ??= $order['qty'] ?? '0';
            $order['filledQty'] ??= $order['cumExecQty'] ?? '0';
            $order['priceAvg'] ??= $order['avgPrice'] ?? '0';
            $order['positionSide'] ??= isset($order['posSide'])
                ? mb_strtoupper((string) $order['posSide'])
                : null;
            $order['clientOrderId'] ??= $order['clientOid'] ?? null;
            $order['status'] ??= $this->normalizeOpenOrderStatus((string) $order['state']);
            $order['_price'] = $this->computeOrderPrice($order);
            $order['_orderType'] = $this->canonicalOrderType($order);

            return $order;
        }, array: $orders);
    }

    /**
     * Compute the effective display price based on order type.
     *
     * - limit: uses price
     * - market: uses priceAvg (if filled) or 0
     * - trigger orders (with triggerPrice): uses triggerPrice
     */
    private function computeOrderPrice(array $order): string
    {
        $orderType = $order['orderType'] ?? '';
        $price = (string) ($order['price'] ?? '0');
        $priceAvg = $order['priceAvg'] ?? '0';
        $triggerPrice = $order['triggerPrice'] ?? null;

        // If there's a trigger price set, this is a conditional order
        if ($triggerPrice !== null && Math::gt((string) $triggerPrice, '0')) {
            return (string) $triggerPrice;
        }

        return match ($orderType) {
            'limit' => $price,
            'market' => Math::gt((string) $priceAvg, '0') ? (string) $priceAvg : '0',
            default => Math::gt($price, '0') ? $price : '0',
        };
    }

    private function normalizeOpenOrderStatus(string $status): string
    {
        return match (mb_strtolower($status)) {
            'new', 'live' => 'NEW',
            'partially_filled', 'partial-fill' => 'PARTIALLY_FILLED',
            'filled', 'full-fill' => 'FILLED',
            'cancelled', 'canceled' => 'CANCELLED',
            default => mb_strtoupper($status),
        };
    }
}
