<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Bitget\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\Order;
use Kraite\Core\Support\ValueObjects\ApiProperties;

trait MapsOrderCancel
{
    public function prepareOrderCancelProperties(Order $order): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $order);
        $properties->set('options.symbol', (string) $order->position->exchangeSymbol->parsed_trading_pair);
        $properties->set('options.productType', 'USDT-FUTURES');
        $properties->set('options.marginCoin', 'USDT');
        $properties->set('options.orderId', (string) $order->exchange_order_id);

        return $properties;
    }

    /**
     * Resolves BitGet order cancel response.
     *
     * BitGet V2 response structure:
     * {
     *     "code": "00000",
     *     "msg": "success",
     *     "data": {
     *         "orderId": "1234567890",
     *         "clientOid": "xxx"
     *     }
     * }
     */
    public function resolveOrderCancelResponse(Response $response): array
    {
        $body = json_decode((string) $response->getBody(), associative: true);
        $result = $body['data'] ?? [];

        return [
            'order_id' => $result['orderId'] ?? '',
            'clientOid' => $result['clientOid'] ?? '',
            'status' => 'CANCELLED',
        ];
    }
}
