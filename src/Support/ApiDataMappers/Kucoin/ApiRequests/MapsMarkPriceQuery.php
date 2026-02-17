<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Kucoin\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Support\ValueObjects\ApiProperties;

trait MapsMarkPriceQuery
{
    /**
     * Prepare properties for querying mark price on KuCoin Futures.
     *
     * @see https://www.kucoin.com/docs/rest/futures-trading/market-data/get-current-mark-price
     */
    public function prepareQueryMarkPriceProperties(ExchangeSymbol $exchangeSymbol): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $exchangeSymbol);
        $properties->set('options.symbol', (string) $exchangeSymbol->parsed_trading_pair);

        return $properties;
    }

    /**
     * Resolve the mark price query response from KuCoin.
     *
     * KuCoin response structure:
     * {
     *     "code": "200000",
     *     "data": {
     *         "symbol": "XBTUSDTM",
     *         "granularity": 1000,
     *         "timePoint": 1557894819000,
     *         "value": 8287.86,
     *         "indexPrice": 8287.86
     *     }
     * }
     */
    public function resolveQueryMarkPriceResponse(Response $response): ?string
    {
        $data = json_decode((string) $response->getBody(), associative: true);
        $markData = $data['data'] ?? [];

        if (! isset($markData['value'])) {
            return null;
        }

        return (string) $markData['value'];
    }
}
