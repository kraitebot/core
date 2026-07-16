<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Bitget\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\ApiDataMappers\Bitget\BitgetProductContext;
use Kraite\Core\Support\ValueObjects\ApiProperties;

trait MapsCancelOrders
{
    public function prepareCancelOrdersProperties(Position $position): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $position);
        $context = BitgetProductContext::fromQuote($position->exchangeSymbol->quote);
        $properties->set('options.productType', $context->productType);
        $properties->set('options.marginCoin', $context->marginCoin);
        $properties->set('options.symbol', (string) $position->exchangeSymbol->asset);

        return $properties;
    }

    /**
     * Resolves BitGet cancel all orders response.
     *
     * BitGet V2 response structure:
     * {
     *     "code": "00000",
     *     "msg": "success",
     *     "data": {
     *         "successList": [
     *             {"orderId": "123", "clientOid": "xxx"},
     *             {"orderId": "456", "clientOid": "yyy"}
     *         ],
     *         "failureList": []
     *     }
     * }
     */
    public function resolveCancelOrdersResponse(Response $response): array
    {
        $body = json_decode((string) $response->getBody(), associative: true);

        return $body['data'] ?? [];
    }
}
