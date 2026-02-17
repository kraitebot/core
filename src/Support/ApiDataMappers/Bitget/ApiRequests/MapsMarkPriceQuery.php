<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Bitget\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Support\ValueObjects\ApiProperties;

trait MapsMarkPriceQuery
{
    public function prepareQueryMarkPriceProperties(ExchangeSymbol $exchangeSymbol): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $exchangeSymbol);
        $properties->set('options.productType', 'USDT-FUTURES');
        $properties->set('options.symbol', (string) $exchangeSymbol->parsed_trading_pair);

        return $properties;
    }

    /**
     * Resolves BitGet symbol price response (mark price).
     *
     * BitGet V2 response structure (data is an array):
     * {
     *     "code": "00000",
     *     "msg": "success",
     *     "data": [
     *         {
     *             "symbol": "BTCUSDT",
     *             "price": "40510.0",
     *             "indexPrice": "40495.2",
     *             "markPrice": "40500.5",
     *             "ts": "1234567890123"
     *         }
     *     ]
     * }
     */
    public function resolveQueryMarkPriceResponse(Response $response): ?string
    {
        $body = json_decode((string) $response->getBody(), associative: true);

        return $body['data'][0]['markPrice'] ?? null;
    }
}
