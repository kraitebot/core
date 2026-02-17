<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Bybit\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Support\ValueObjects\ApiProperties;

trait MapsMarkPriceQuery
{
    /**
     * Prepare properties for querying mark price on Bybit.
     *
     * Uses the tickers endpoint which includes mark price.
     *
     * @see https://bybit-exchange.github.io/docs/v5/market/tickers
     */
    public function prepareQueryMarkPriceProperties(ExchangeSymbol $exchangeSymbol): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $exchangeSymbol);
        $properties->set('options.category', 'linear');
        $properties->set('options.symbol', (string) $exchangeSymbol->parsed_trading_pair);

        return $properties;
    }

    /**
     * Resolve the mark price response from Bybit tickers endpoint.
     *
     * Bybit V5 response structure (GET /v5/market/tickers):
     * {
     *     "retCode": 0,
     *     "result": {
     *         "category": "linear",
     *         "list": [{
     *             "symbol": "BTCUSDT",
     *             "lastPrice": "16597.00",
     *             "indexPrice": "16598.54",
     *             "markPrice": "16596.00",
     *             ...
     *         }]
     *     }
     * }
     */
    public function resolveQueryMarkPriceResponse(Response $response): ?string
    {
        $data = json_decode((string) $response->getBody(), associative: true);
        $list = $data['result']['list'] ?? [];

        if (empty($list)) {
            return null;
        }

        return $list[0]['markPrice'] ?? null;
    }
}
