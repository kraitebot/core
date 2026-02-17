<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Kucoin\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Support\ValueObjects\ApiProperties;

trait MapsKlinesQuery
{
    /**
     * Prepare properties for klines query.
     *
     * Note: KuCoin uses `granularity` in minutes (e.g., 5 for 5 minutes).
     * Note: KuCoin symbols have an 'M' suffix for perpetuals (e.g., XBTUSDTM).
     * Note: KuCoin doesn't have a limit param - we calculate time range from limit.
     *
     * @see https://www.kucoin.com/docs/rest/futures-trading/market-data/get-klines
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
        $properties->set('options.granularity', $this->convertIntervalForKucoin($interval));

        // KuCoin doesn't support a limit param - use time range instead
        // If limit is set and no explicit time range, calculate from limit
        if ($limit !== null && $startTime === null && $endTime === null) {
            $intervalSeconds = $this->getIntervalSeconds($interval);
            $nowMs = (int) (microtime(true) * 1000);
            $fromMs = $nowMs - ($limit * $intervalSeconds * 1000);

            $properties->set('options.from', $fromMs);
            $properties->set('options.to', $nowMs);
        } else {
            if ($startTime !== null) {
                $properties->set('options.from', $startTime);
            }

            if ($endTime !== null) {
                $properties->set('options.to', $endTime);
            }
        }

        return $properties;
    }

    /**
     * Convert interval string to seconds.
     */
    private function getIntervalSeconds(string $interval): int
    {
        $map = [
            '1m' => 60,
            '5m' => 300,
            '15m' => 900,
            '30m' => 1800,
            '1h' => 3600,
            '2h' => 7200,
            '4h' => 14400,
            '8h' => 28800,
            '12h' => 43200,
            '1d' => 86400,
            '1w' => 604800,
        ];

        return $map[$interval] ?? 300;
    }

    /**
     * Resolve klines response into normalized array.
     *
     * KuCoin returns: { data: [[time, open, high, low, close, volume], ...] }
     *
     * @return array<int, array{timestamp: int, open: string, high: string, low: string, close: string, volume: string}>
     */
    public function resolveQueryKlinesResponse(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), associative: true);

        $list = $data['data'] ?? [];

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
     * Convert canonical interval format to KuCoin granularity (in minutes).
     *
     * KuCoin uses integer minutes for granularity.
     */
    private function convertIntervalForKucoin(string $interval): int
    {
        // Map canonical intervals to minutes
        $map = [
            '1m' => 1,
            '5m' => 5,
            '15m' => 15,
            '30m' => 30,
            '1h' => 60,
            '2h' => 120,
            '4h' => 240,
            '8h' => 480,
            '12h' => 720,
            '1d' => 1440,
            '1w' => 10080,
        ];

        return $map[$interval] ?? 5;
    }
}
