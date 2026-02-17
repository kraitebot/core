<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Bitget\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Support\ValueObjects\ApiProperties;

trait MapsKlinesQuery
{
    /**
     * Prepare properties for klines query.
     *
     * Note: BitGet Futures API uses uppercase for hour/day/week timeframes:
     * - Minutes: 1m, 3m, 5m, 15m, 30m (lowercase)
     * - Hours: 1H, 2H, 4H, 6H, 12H (uppercase H)
     * - Days: 1D, 3D (uppercase D)
     * - Weeks: 1W (uppercase W)
     *
     * @see https://www.bitget.com/api-doc/contract/market/Get-Candle-Data
     */
    public function prepareQueryKlinesProperties(
        ExchangeSymbol $exchangeSymbol,
        string $granularity = '5m',
        ?int $startTime = null,
        ?int $endTime = null,
        ?int $limit = null
    ): ApiProperties {
        $properties = new ApiProperties;
        $properties->set('relatable', $exchangeSymbol);
        $properties->set('options.symbol', (string) $exchangeSymbol->parsed_trading_pair);
        $properties->set('options.granularity', $this->normalizeBitgetGranularity($granularity));
        $properties->set('options.productType', 'USDT-FUTURES');

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
     * BitGet returns: { data: [["ts", "o", "h", "l", "c", "vol", "quoteVol"], ...] }
     * Note: Response data is array of string arrays.
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
     * Normalize granularity to Bitget Futures API format.
     *
     * Bitget Futures API requires uppercase for hour/day/week timeframes:
     * - 1h → 1H, 4h → 4H, 12h → 12H
     * - 1d → 1D, 3d → 3D
     * - 1w → 1W
     *
     * Minutes stay lowercase: 1m, 5m, 15m, 30m
     */
    private function normalizeBitgetGranularity(string $granularity): string
    {
        // Match patterns like "4h", "12h", "1d", "1w" and uppercase the suffix
        if (preg_match('/^(\d+)([hdw])$/i', $granularity, $matches)) {
            $number = $matches[1];
            $unit = strtolower($matches[2]);

            // Hours, days, weeks use uppercase in Bitget Futures API
            if (in_array($unit, ['h', 'd', 'w'], true)) {
                return $number . strtoupper($unit);
            }
        }

        // Minutes and already-correct formats pass through unchanged
        return $granularity;
    }
}
