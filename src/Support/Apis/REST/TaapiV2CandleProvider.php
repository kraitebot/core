<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Apis\REST;

use InvalidArgumentException;
use JsonException;
use Kraite\Core\Support\ApiClients\REST\BinanceApiClient;
use Kraite\Core\Support\ValueObjects\ApiProperties;
use Kraite\Core\Support\ValueObjects\ApiRequest;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final class TaapiV2CandleProvider
{
    private ?BinanceApiClient $futures = null;

    private ?BinanceApiClient $spot = null;

    /**
     * @param  array<string, mixed>  $loggingContext
     * @return array<int, array{timestamp: int, open: int|float, high: int|float, low: int|float, close: int|float, volume: int|float}>
     */
    public function get(
        string $exchange,
        string $symbol,
        string $timeframe,
        int $limit,
        array $loggingContext = [],
    ): array {
        $properties = new ApiProperties([
            ...$loggingContext,
            'options' => [
                'symbol' => $this->normalizeSymbol($symbol),
                'interval' => $timeframe,
                'limit' => $limit,
            ],
        ]);

        $response = match ($exchange) {
            'binancefutures' => $this->futures()->publicRequest(ApiRequest::make(
                'GET',
                '/fapi/v1/klines',
                $properties,
            )),
            'binance' => $this->spot()->publicRequest(ApiRequest::make(
                'GET',
                '/api/v3/klines',
                $properties,
            )),
            default => throw new InvalidArgumentException(
                "TAAPI v2 bulk calculations require a direct candle provider for exchange {$exchange}.",
            ),
        };

        if (! $response instanceof ResponseInterface) {
            throw new RuntimeException('Binance returned an invalid klines response type.');
        }

        return $this->normalize($response);
    }

    /**
     * @return array<int, array{timestamp: int, open: int|float, high: int|float, low: int|float, close: int|float, volume: int|float}>
     */
    private function normalize(ResponseInterface $response): array
    {
        try {
            $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Binance returned malformed klines JSON.', previous: $exception);
        }

        if (! is_array($decoded) || ! array_is_list($decoded) || $decoded === []) {
            throw new RuntimeException('Binance returned an empty or invalid klines list.');
        }

        $candles = [];

        foreach ($decoded as $index => $kline) {
            if (! is_array($kline) || count($kline) < 6) {
                throw new RuntimeException("Binance returned a malformed kline at index {$index}.");
            }

            $timestamp = $kline[0] ?? null;
            if (! is_int($timestamp) && ! (is_string($timestamp) && ctype_digit($timestamp))) {
                throw new RuntimeException("Binance returned a malformed kline timestamp at index {$index}.");
            }

            $candles[] = [
                'timestamp' => $this->normalizeTimestamp((int) $timestamp),
                'open' => $this->numericValue($kline[1] ?? null, $index, 'open'),
                'high' => $this->numericValue($kline[2] ?? null, $index, 'high'),
                'low' => $this->numericValue($kline[3] ?? null, $index, 'low'),
                'close' => $this->numericValue($kline[4] ?? null, $index, 'close'),
                'volume' => $this->numericValue($kline[5] ?? null, $index, 'volume'),
            ];
        }

        return $candles;
    }

    private function normalizeSymbol(string $symbol): string
    {
        return str_replace(['/', '-'], '', mb_strtoupper($symbol));
    }

    private function futures(): BinanceApiClient
    {
        return $this->futures ??= new BinanceApiClient([
            'url' => config('kraite.api.url.binance.rest', 'https://fapi.binance.com'),
            'api_key' => null,
            'api_secret' => null,
        ]);
    }

    private function spot(): BinanceApiClient
    {
        return $this->spot ??= new BinanceApiClient([
            'url' => config('kraite.api.url.binance.market_data_rest', 'https://data-api.binance.vision'),
            'api_key' => null,
            'api_secret' => null,
        ]);
    }

    private function normalizeTimestamp(int $timestamp): int
    {
        return $timestamp >= 1_000_000_000_000
            ? intdiv($timestamp, 1000)
            : $timestamp;
    }

    private function numericValue(mixed $value, int $index, string $field): int|float
    {
        if (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value))) {
            throw new RuntimeException("Binance returned a malformed {$field} value at kline index {$index}.");
        }

        $numeric = (float) $value;

        return floor($numeric) === $numeric ? (int) $numeric : $numeric;
    }
}
