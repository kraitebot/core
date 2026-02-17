<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Bybit\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\Order;
use Kraite\Core\Support\ValueObjects\ApiProperties;

trait MapsOrderModify
{
    /**
     * Prepare properties for amending/modifying an order on Bybit.
     *
     * @see https://bybit-exchange.github.io/docs/v5/order/amend-order
     */
    public function prepareOrderModifyProperties(Order $order, $quantity, $price): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $order);
        $properties->set('options.category', 'linear');
        $properties->set('options.symbol', (string) $order->position->exchangeSymbol->parsed_trading_pair);
        $properties->set('options.orderId', (string) $order->exchange_order_id);
        $properties->set('options.qty', (string) api_format_quantity($quantity, $order->position->exchangeSymbol));
        $properties->set('options.price', (string) api_format_price($price, $order->position->exchangeSymbol));

        return $properties;
    }

    /**
     * Resolve the amend order response from Bybit.
     *
     * Bybit V5 response structure:
     * {
     *     "retCode": 0,
     *     "retMsg": "OK",
     *     "result": {
     *         "orderId": "c6f055d9-7f21-4079-913d-e6523a9cfffa",
     *         "orderLinkId": "linear-004"
     *     }
     * }
     */
    public function resolveOrderModifyResponse(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), associative: true);
        $result = $data['result'] ?? [];

        return [
            'order_id' => $result['orderId'] ?? null,
            'client_order_id' => $result['orderLinkId'] ?? null,
            'status' => ! empty($result['orderId']) ? 'AMENDED' : 'FAILED',
            '_raw' => $data,
        ];
    }
}
