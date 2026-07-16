<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Bitget\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\ApiDataMappers\Bitget\BitgetProductContext;
use Kraite\Core\Support\ValueObjects\ApiProperties;

trait MapsSymbolMarginType
{
    /**
     * Prepare properties for updating margin mode on BitGet.
     *
     * BitGet expects lowercase: 'isolated' or 'crossed'.
     */
    public function prepareUpdateMarginTypeProperties(Position $position): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $position);
        $properties->set('options.symbol', (string) $position->exchangeSymbol->asset);
        $context = BitgetProductContext::fromQuote($position->exchangeSymbol->quote);
        $properties->set('options.productType', $context->productType);
        $properties->set('options.marginCoin', $context->marginCoin);

        // Get margin mode from account (already lowercase: isolated/crossed)
        $marginMode = mb_strtolower($position->account->margin_mode);
        $properties->set('options.marginMode', $marginMode);

        return $properties;
    }

    /**
     * Resolves BitGet set margin mode response.
     *
     * BitGet V2 response structure:
     * {
     *     "code": "00000",
     *     "msg": "success",
     *     "data": {
     *         "symbol": "BTCUSDT",
     *         "marginCoin": "USDT",
     *         "marginMode": "crossed"
     *     }
     * }
     */
    public function resolveUpdateMarginTypeResponse(Response $response): array
    {
        $body = json_decode((string) $response->getBody(), associative: true);

        return $body['data'] ?? [];
    }
}
