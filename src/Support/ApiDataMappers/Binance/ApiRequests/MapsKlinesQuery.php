<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Binance\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Support\ValueObjects\ApiProperties;

trait MapsKlinesQuery
{
    /**
     * Prepare properties for klines query.
     *
     * @see https://binance-docs.github.io/apidocs/futures/en/#kline-candlestick-data
     */
    public function prepareQueryKlinesProperties(
        ExchangeSymbol $exchangeSymbol,
        string $interval = '5m',
        ?int $startTime = null,
        ?int $endTime = null,
        ?int $limit = null
    ): ApiProperties {
        $properties = new ApiProperties;
        $properties->set('relatable', $exchangeSymbol);
        $properties->set('options.symbol', (string) $exchangeSymbol->parsed_trading_pair);
        $properties->set('options.interval', $interval);

        if ($startTime !== null) {
            $properties->set('options.startTime', $startTime);
        }

        if ($endTime !== null) {
            $properties->set('options.endTime', $endTime);
        }

        if ($limit !== null) {
            $properties->set('options.limit', $limit);
        }

        return $properties;
    }

    /**
     * Resolve klines response into normalized array.
     *
     * Binance returns: [openTime, open, high, low, close, volume, closeTime, quoteVolume, trades, takerBuyBase, takerBuyQuote, ignore]
     *
     * @return array<int, array{timestamp: int, open: string, high: string, low: string, close: string, volume: string}>
     */
    public function resolveQueryKlinesResponse(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), associative: true);

        if (! is_array($data)) {
            return [];
        }

        $normalized = [];

        foreach ($data as $candle) {
            if (! is_array($candle) || count($candle) < 6) {
                continue;
            }

            $normalized[] = [
                'timestamp' => (int) $candle[0],
                'open' => (string) $candle[1],
                'high' => (string) $candle[2],
                'low' => (string) $candle[3],
                'close' => (string) $candle[4],
                'volume' => (string) $candle[5],
            ];
        }

        return $normalized;
    }
}
