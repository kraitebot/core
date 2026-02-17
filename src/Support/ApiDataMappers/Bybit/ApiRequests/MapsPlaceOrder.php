<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Bybit\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Str;
use Kraite\Core\Models\Order;
use Kraite\Core\Support\ValueObjects\ApiProperties;

trait MapsPlaceOrder
{
    /**
     * Prepare properties for placing an order on Bybit.
     *
     * @see https://bybit-exchange.github.io/docs/v5/order/create-order
     */
    public function preparePlaceOrderProperties(Order $order): ApiProperties
    {
        // Auto-generate client order ID if null
        if (is_null($order->client_order_id)) {
            $order->updateSaving(['client_order_id' => Str::uuid()->toString()]);
        }

        $properties = new ApiProperties;
        $properties->set('relatable', $order);
        $properties->set('options.category', 'linear');
        $properties->set('options.orderLinkId', (string) $order->client_order_id);
        $properties->set('options.symbol', (string) $order->position->exchangeSymbol->parsed_trading_pair);
        $properties->set('options.side', (string) $this->sideType($order->side));
        $properties->set('options.qty', (string) api_format_quantity($order->quantity, $order->position->exchangeSymbol));

        switch ($order->type) {
            case 'PROFIT-LIMIT':
            case 'LIMIT':
                $properties->set('options.orderType', 'Limit');
                $properties->set('options.price', (string) api_format_price($order->price, $order->position->exchangeSymbol));
                $properties->set('options.timeInForce', 'GTC');
                break;

            case 'MARKET':
            case 'MARKET-MAGNET':
            case 'MARKET-CANCEL':
                $properties->set('options.orderType', 'Market');
                break;

            case 'STOP-MARKET':
                $properties->set('options.orderType', 'Market');
                $properties->set('options.triggerPrice', (string) api_format_price($order->price, $order->position->exchangeSymbol));
                $properties->set('options.triggerDirection', $order->side === 'BUY' ? 1 : 2);
                break;

            case 'TAKE-PROFIT':
                $properties->set('options.orderType', 'Market');
                $properties->set('options.triggerPrice', (string) api_format_price($order->price, $order->position->exchangeSymbol));
                $properties->set('options.triggerDirection', $order->side === 'SELL' ? 2 : 1);
                break;
        }

        // Set reduceOnly if closing position
        if ($order->reduce_only ?? false) {
            $properties->set('options.reduceOnly', true);
        }

        // Position index for hedge mode (0 = one-way, 1 = buy side, 2 = sell side)
        $properties->set('options.positionIdx', 0);

        return $properties;
    }

    /**
     * Resolve the place order response from Bybit.
     *
     * Bybit V5 response structure:
     * {
     *     "retCode": 0,
     *     "retMsg": "OK",
     *     "result": {
     *         "orderId": "1321003749386327552",
     *         "orderLinkId": "test-orderLinkId"
     *     }
     * }
     */
    public function resolvePlaceOrderResponse(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), associative: true);
        $result = $data['result'] ?? [];

        return [
            'orderId' => $result['orderId'] ?? null,
            'clientOrderId' => $result['orderLinkId'] ?? null,
            'status' => 'NEW',
            '_raw' => $data,
        ];
    }
}
