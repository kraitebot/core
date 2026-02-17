<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Bybit\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\Order;
use Kraite\Core\Support\ValueObjects\ApiProperties;

trait MapsOrderCancel
{
    /**
     * Prepare properties for canceling a single order on Bybit.
     *
     * @see https://bybit-exchange.github.io/docs/v5/order/cancel-order
     */
    public function prepareOrderCancelProperties(Order $order): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $order);
        $properties->set('options.category', 'linear');
        $properties->set('options.symbol', (string) $order->position->exchangeSymbol->parsed_trading_pair);
        $properties->set('options.orderId', (string) $order->exchange_order_id);

        return $properties;
    }

    /**
     * Resolve the cancel order response from Bybit.
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
    public function resolveOrderCancelResponse(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), associative: true);
        $result = $data['result'] ?? [];

        return [
            'orderId' => $result['orderId'] ?? null,
            'clientOrderId' => $result['orderLinkId'] ?? null,
            'success' => ! empty($result['orderId']),
            '_raw' => $data,
        ];
    }
}
