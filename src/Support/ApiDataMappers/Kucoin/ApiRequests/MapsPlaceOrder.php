<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Kucoin\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Str;
use Kraite\Core\Models\Order;
use Kraite\Core\Support\ValueObjects\ApiProperties;

trait MapsPlaceOrder
{
    /**
     * Prepare properties for placing an order on KuCoin Futures.
     *
     * @see https://www.kucoin.com/docs/rest/futures-trading/orders/place-order
     */
    public function preparePlaceOrderProperties(Order $order): ApiProperties
    {
        // Auto-generate client order ID if null
        if (is_null($order->client_order_id)) {
            $order->updateSaving(['client_order_id' => Str::uuid()->toString()]);
        }

        $properties = new ApiProperties;
        $properties->set('relatable', $order);
        $properties->set('options.clientOid', (string) $order->client_order_id);
        $properties->set('options.symbol', (string) $order->position->exchangeSymbol->parsed_trading_pair);
        $properties->set('options.side', (string) $this->sideType($order->side));
        $properties->set('options.size', (int) api_format_quantity($order->quantity, $order->position->exchangeSymbol));
        $properties->set('options.leverage', (int) ($order->position->leverage ?? 1));

        switch ($order->type) {
            case 'PROFIT-LIMIT':
            case 'LIMIT':
                $properties->set('options.type', 'limit');
                $properties->set('options.price', (string) api_format_price($order->price, $order->position->exchangeSymbol));
                $properties->set('options.timeInForce', 'GTC');
                break;

            case 'MARKET':
            case 'MARKET-MAGNET':
            case 'MARKET-CANCEL':
                $properties->set('options.type', 'market');
                break;

            case 'STOP-MARKET':
                $properties->set('options.type', 'market');
                // Stop direction: BUY closes SHORT (price rises to trigger), SELL closes LONG (price falls)
                $properties->set('options.stop', $order->side === 'BUY' ? 'up' : 'down');
                $properties->set('options.stopPriceType', 'MP');
                $properties->set('options.stopPrice', (string) api_format_price($order->price, $order->position->exchangeSymbol));
                break;

            case 'TAKE-PROFIT':
                $properties->set('options.type', 'market');
                // Take-profit direction: opposite of stop-loss (SELL closes LONG at higher price, BUY closes SHORT at lower)
                $properties->set('options.stop', $order->side === 'SELL' ? 'up' : 'down');
                $properties->set('options.stopPriceType', 'MP');
                $properties->set('options.stopPrice', (string) api_format_price($order->price, $order->position->exchangeSymbol));
                break;
        }

        // Set reduceOnly if closing position
        if ($order->reduce_only ?? false) {
            $properties->set('options.reduceOnly', true);
        }

        return $properties;
    }

    /**
     * Resolve the place order response from KuCoin.
     *
     * KuCoin response structure:
     * {
     *     "code": "200000",
     *     "data": {
     *         "orderId": "5bd6e9286d99522a52e458de",
     *         "clientOid": "client-order-id"
     *     }
     * }
     */
    public function resolvePlaceOrderResponse(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), associative: true);
        $orderData = $data['data'] ?? [];

        return [
            'orderId' => $orderData['orderId'] ?? null,
            'clientOrderId' => $orderData['clientOid'] ?? null,
            'status' => 'NEW',
            '_raw' => $data,
        ];
    }
}
