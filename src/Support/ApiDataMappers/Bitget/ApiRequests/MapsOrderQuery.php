<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Bitget\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\Order;
use Kraite\Core\Support\ApiDataMappers\Bitget\BitgetProductContext;
use Kraite\Core\Support\Math;
use Kraite\Core\Support\ValueObjects\ApiProperties;

trait MapsOrderQuery
{
    public function prepareOrderQueryProperties(Order $order): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $order);
        $properties->set('options.symbol', (string) $order->position->exchangeSymbol->asset);
        $properties->set(
            'options.productType',
            BitgetProductContext::fromQuote($order->position->exchangeSymbol->quote)->productType
        );
        $properties->set('options.orderId', (string) $order->exchange_order_id);

        return $properties;
    }

    /**
     * Resolves BitGet order query response.
     *
     * BitGet V2 response structure:
     * {
     *     "code": "00000",
     *     "data": {
     *         "symbol": "BTCUSDT",
     *         "size": "0.001",
     *         "orderId": "1234567890",
     *         "clientOid": "xxx",
     *         "filledQty": "0.0005",
     *         "priceAvg": "40100",
     *         "fee": "0.02",
     *         "price": "40000",
     *         "state": "filled",
     *         "side": "buy",
     *         "orderType": "limit",
     *         "leverage": "10",
     *         "marginMode": "crossed",
     *         "posSide": "long",
     *         "tradeSide": "open",
     *         "cTime": "1627116936176",
     *         "uTime": "1627116936180"
     *     }
     * }
     */
    public function resolveOrderQueryResponse(Response $response): array
    {
        $body = json_decode((string) $response->getBody(), associative: true);
        $result = $body['data'] ?? [];

        $raw = $result;

        // Normalize state to uppercase status (like Binance).
        $status = $this->normalizeOrderState($result['state'] ?? '');

        // Smart price selection: priceAvg if filled, else price.
        $priceAvg = $result['priceAvg'] ?? '0';
        $price = Math::gt($priceAvg, 0) ? $priceAvg : ($result['price'] ?? '0');

        // Smart quantity selection: filledQty if > 0, else size.
        $filledQty = $result['filledQty'] ?? '0';
        $quantity = Math::gt($filledQty, 0) ? $filledQty : ($result['size'] ?? '0');

        return [
            'order_id' => $result['orderId'] ?? '',
            'symbol' => $this->identifyBaseAndQuote($result['symbol'] ?? ''),
            'status' => $status,
            'price' => $price,
            '_price' => $this->computeOrderQueryPrice($result),
            'quantity' => $quantity,
            'type' => $result['orderType'] ?? '',
            '_orderType' => $this->canonicalOrderType($result),
            'side' => $result['side'] ?? '',
            '_raw' => $raw,
        ];
    }

    /**
     * Normalize BitGet order state to canonical uppercase status.
     *
     * BitGet states: new, partially_filled, filled, cancelled
     * Canonical: NEW, PARTIALLY_FILLED, FILLED, CANCELLED
     */
    private function normalizeOrderState(string $state): string
    {
        return match (mb_strtolower($state)) {
            'new', 'live' => 'NEW',
            'partially_filled', 'partial-fill' => 'PARTIALLY_FILLED',
            'filled', 'full-fill' => 'FILLED',
            'cancelled', 'canceled' => 'CANCELLED',
            default => mb_strtoupper($state),
        };
    }

    /**
     * Compute the effective display price based on order type.
     *
     * - limit: uses price
     * - market: uses priceAvg (if filled) or 0
     * - trigger orders (with triggerPrice): uses triggerPrice
     */
    private function computeOrderQueryPrice(array $order): string
    {
        $orderType = mb_strtolower($order['orderType'] ?? '');
        $price = (string) ($order['price'] ?? '0');
        $priceAvg = $order['priceAvg'] ?? '0';
        $triggerPrice = $order['triggerPrice'] ?? '0';

        // If there's a trigger price set, this is a conditional order.
        if (Math::gt($triggerPrice, 0)) {
            return (string) $triggerPrice;
        }

        return match ($orderType) {
            'limit' => $price,
            'market' => Math::gt($priceAvg, 0) ? (string) $priceAvg : '0',
            default => Math::gt($price, 0) ? $price : '0',
        };
    }
}
