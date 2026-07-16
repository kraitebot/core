<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Bitget\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Str;
use Kraite\Core\Models\Order;
use Kraite\Core\Support\ApiDataMappers\Bitget\BitgetProductContext;
use Kraite\Core\Support\ValueObjects\ApiProperties;

trait MapsOrderModify
{
    public function prepareOrderModifyProperties(Order $order, $quantity, $price): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $order);
        $properties->set('options.symbol', (string) $order->position->exchangeSymbol->asset);
        $context = BitgetProductContext::fromQuote($order->position->exchangeSymbol->quote);
        $properties->set('options.productType', $context->productType);
        $properties->set('options.marginCoin', $context->marginCoin);
        $properties->set('options.orderId', (string) $order->exchange_order_id);
        $properties->set('options.newSize', (string) $quantity);
        $properties->set('options.newPrice', (string) $price);
        $properties->set('options.newClientOid', Str::uuid()->toString());

        return $properties;
    }

    /**
     * Resolves BitGet order modify response.
     *
     * BitGet V2 response structure:
     * {
     *     "code": "00000",
     *     "msg": "success",
     *     "data": {
     *         "orderId": "1234567890",
     *         "clientOid": "newClientOid123"
     *     }
     * }
     */
    public function resolveOrderModifyResponse(Response $response): array
    {
        $body = json_decode((string) $response->getBody(), associative: true);
        $result = $body['data'] ?? [];

        return [
            'order_id' => $result['orderId'] ?? '',
            'clientOid' => $result['clientOid'] ?? '',
        ];
    }
}
