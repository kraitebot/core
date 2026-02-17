<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Bybit\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Support\ValueObjects\ApiProperties;

trait MapsKlinesQuery
{
    /**
     * Prepare properties for klines query.
     *
     * Note: Bybit uses interval as just the number (e.g., "5" for 5 minutes, not "5m").
     *
     * @see https://bybit-exchange.github.io/docs/v5/market/kline
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
        $properties->set('options.interval', $this->convertIntervalForBybit($interval));
        $properties->set('options.category', 'linear');

        if ($startTime !== null) {
            $properties->set('options.start', $startTime);
        }

        if ($endTime !== null) {
            $properties->set('options.end', $endTime);
        }

        if ($limit !== null) {
            $properties->set('options.limit', $limit);
        }

        return $properties;
    }

    /**
     * Resolve klines response into normalized array.
     *
     * Bybit returns: { result: { list: [[timestamp, open, high, low, close, volume, turnover], ...] } }
     *
     * @return array<int, array{timestamp: int, open: string, high: string, low: string, close: string, volume: string}>
     */
    public function resolveQueryKlinesResponse(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), associative: true);

        $list = $data['result']['list'] ?? [];

        if (! is_array($list)) {
            return [];
        }

        $normalized = [];

        foreach ($list as $candle) {
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

    /**
     * Convert canonical interval format to Bybit format.
     *
     * Bybit uses just the number for minute intervals (e.g., "5" not "5m").
     * For hourly/daily, it uses "60", "120", "240", "360", "720", "D", "W", "M".
     */
    private function convertIntervalForBybit(string $interval): string
    {
        // Map canonical intervals to Bybit format
        $map = [
            '1m' => '1',
            '3m' => '3',
            '5m' => '5',
            '15m' => '15',
            '30m' => '30',
            '1h' => '60',
            '2h' => '120',
            '4h' => '240',
            '6h' => '360',
            '12h' => '720',
            '1d' => 'D',
            '1w' => 'W',
            '1M' => 'M',
        ];

        return $map[$interval] ?? $interval;
    }
}
