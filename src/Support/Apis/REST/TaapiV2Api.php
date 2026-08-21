<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Apis\REST;

use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use JsonException;
use Kraite\Core\Contracts\TaapiApiDriver;
use Kraite\Core\Support\ApiClients\REST\TaapiV2ApiClient;
use Kraite\Core\Support\ValueObjects\ApiCredentials;
use Kraite\Core\Support\ValueObjects\ApiProperties;
use Kraite\Core\Support\ValueObjects\ApiRequest;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final class TaapiV2Api implements TaapiApiDriver
{
    private const CANDLES_PER_CONSTRUCT = 200;

    private TaapiV2ApiClient $client;

    private ?TaapiV2CandleProvider $candles = null;

    public function __construct(ApiCredentials $credentials)
    {
        $token = $credentials->getOr(
            'taapi_v2_token',
            config('kraite.api.credentials.taapi.v2_token'),
        );

        if (! is_string($token) || $token === '') {
            throw new RuntimeException('TAAPI v2 token is not configured.');
        }

        $this->client = new TaapiV2ApiClient([
            'url' => $this->baseUrl(),
            'token' => $token,
        ]);
    }

    public function getGroupedIndicatorsValues(ApiProperties $properties): ResponseInterface
    {
        $bulkProperties = new ApiProperties([
            ...$this->loggingContext($properties),
            'constructs' => [[
                'exchange' => $properties->get('options.exchange'),
                'symbol' => $properties->get('options.symbol'),
                'interval' => $properties->get('options.interval'),
                'indicators' => $properties->get('options.indicators'),
            ]],
        ]);

        return $this->getBulkIndicatorsValues($bulkProperties);
    }

    public function getBulkIndicatorsValues(ApiProperties $properties): ResponseInterface
    {
        $constructs = $properties->get('constructs');

        if (! is_array($constructs) || ! array_is_list($constructs) || $constructs === []) {
            throw new InvalidArgumentException('TAAPI v2 bulk requests require a non-empty constructs list.');
        }

        $entries = [];
        $manualConstructs = [];
        $indicatorMaps = [];
        $sequence = 0;

        foreach ($constructs as $constructIndex => $construct) {
            if (! is_array($construct)) {
                throw new InvalidArgumentException("TAAPI construct {$constructIndex} must be an array.");
            }

            $constructId = 'construct_'.$constructIndex;
            $exchange = $this->requiredString($construct, 'exchange', $constructIndex);
            $symbol = $this->requiredString($construct, 'symbol', $constructIndex);
            $timeframe = $this->requiredString($construct, 'interval', $constructIndex);
            $indicators = $construct['indicators'] ?? null;

            if (! is_array($indicators) || ! array_is_list($indicators) || $indicators === []) {
                throw new InvalidArgumentException("TAAPI construct {$constructIndex} requires indicators.");
            }

            $candles = array_key_exists('candles', $construct)
                ? $this->providedCandles($construct['candles'], $constructIndex)
                : $this->candleProvider()->get(
                    exchange: $exchange,
                    symbol: $symbol,
                    timeframe: $timeframe,
                    limit: self::CANDLES_PER_CONSTRUCT,
                    loggingContext: $this->loggingContext($properties),
                );
            $manualIndicators = [];

            foreach ($indicators as $indicatorIndex => $indicator) {
                if (! is_array($indicator)) {
                    throw new InvalidArgumentException(
                        "TAAPI indicator {$indicatorIndex} in construct {$constructIndex} must be an array.",
                    );
                }

                $originalId = isset($indicator['id']) && is_string($indicator['id'])
                    ? $indicator['id']
                    : 'indicator_'.$indicatorIndex;
                $indicatorName = $this->requiredString($indicator, 'indicator', $constructIndex);

                if ($indicatorName === 'candle') {
                    $entries[$sequence++] = [
                        'id' => $originalId,
                        'indicator' => $indicatorName,
                        'result' => $this->candleResult(
                            $candles,
                            $this->integerParameter($indicator['results'] ?? null, 1, 1),
                            $this->integerParameter($indicator['backtrack'] ?? null, 0, 0),
                        ),
                        'errors' => [],
                    ];

                    continue;
                }

                $safeId = 'indicator_'.$indicatorIndex;
                $parameters = $indicator;
                $parameters['id'] = $safeId;
                unset($parameters['endpoint'], $parameters['interval'], $parameters['addResultTimestamp']);
                $manualIndicators[] = $parameters;
                $indicatorMaps[$constructId][$safeId] = [
                    'sequence' => $sequence++,
                    'id' => $originalId,
                    'indicator' => $indicatorName,
                ];
            }

            if ($manualIndicators !== []) {
                $manualConstructs[] = [
                    'id' => $constructId,
                    'candles' => $candles,
                    'indicators' => $manualIndicators,
                ];
            }
        }

        if ($manualConstructs !== []) {
            $bulkResponse = $this->decodeObject($this->requestOnce(ApiRequest::make(
                'POST',
                '/bulk-candles',
                new ApiProperties([
                    ...$this->loggingContext($properties),
                    'verbose' => true,
                    'constructs' => $manualConstructs,
                ]),
            )));

            foreach ($indicatorMaps as $constructId => $constructIndicators) {
                $constructResponse = $bulkResponse[$constructId] ?? null;
                $constructError = is_array($constructResponse) && is_string($constructResponse['_error'] ?? null)
                    ? $constructResponse['_error']
                    : null;

                foreach ($constructIndicators as $safeId => $mapping) {
                    $envelope = is_array($constructResponse) ? ($constructResponse[$safeId] ?? null) : null;
                    $result = is_array($envelope) && array_key_exists('result', $envelope)
                        ? $envelope['result']
                        : (is_array($envelope) ? $envelope : null);
                    $errors = [];
                    if (is_array($envelope) && is_array($envelope['errors'] ?? null)) {
                        foreach ($envelope['errors'] as $error) {
                            if (is_string($error)) {
                                $errors[] = $error;
                            }
                        }
                    }

                    if ($constructError !== null) {
                        $errors[] = $constructError;
                    }

                    if ($envelope === null && $constructError === null) {
                        $errors[] = "TAAPI v2 omitted {$safeId}.";
                    }

                    $entries[$mapping['sequence']] = [
                        'id' => $mapping['id'],
                        'indicator' => $mapping['indicator'],
                        'result' => $result,
                        'errors' => array_values(array_unique($errors)),
                    ];
                }
            }
        }

        ksort($entries);

        return $this->legacyResponse(['data' => array_values($entries)]);
    }

    public function getIndicatorValues(ApiProperties $properties): ResponseInterface
    {
        $options = $properties->getOr('options', []);

        if (! is_array($options)) {
            throw new InvalidArgumentException('TAAPI indicator options must be an array.');
        }

        $endpoint = $options['endpoint'] ?? null;
        if (! is_string($endpoint) || $endpoint === '') {
            throw new InvalidArgumentException('TAAPI indicator endpoint is required.');
        }

        $timeframe = $options['timeframe'] ?? $options['interval'] ?? null;
        $symbol = $options['symbol'] ?? null;
        if (! is_string($symbol) || $symbol === '') {
            throw new InvalidArgumentException('TAAPI indicator symbol is required.');
        }

        unset($options['endpoint'], $options['interval'], $options['secret'], $options['addResultTimestamp']);
        $options['timeframe'] = $timeframe;
        $options['symbol'] = $this->normalizeSymbol($symbol);

        $path = in_array($endpoint, ['candle', 'candles'], true)
            ? '/candles'
            : '/indicator/'.$endpoint;

        return $this->requestOnce(ApiRequest::make(
            'GET',
            $path,
            new ApiProperties([
                ...$this->loggingContext($properties),
                'options' => $options,
            ]),
        ));
    }

    public function baseUrl(): string
    {
        $url = config('kraite.api.url.taapi.v2', 'https://v2.taapi.io');

        return is_string($url) && $url !== '' ? $url : 'https://v2.taapi.io';
    }

    private function requestOnce(ApiRequest $request): ResponseInterface
    {
        $response = $this->client->publicRequest($request);

        if ($response->getStatusCode() === 202) {
            throw new RuntimeException('TAAPI v2 data is pending; no poll sent to preserve the one-call budget.');
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeObject(ResponseInterface $response): array
    {
        $decoded = $this->decode($response);

        if (array_is_list($decoded)) {
            throw new RuntimeException('TAAPI v2 bulk response was not an object.');
        }

        return $this->stringKeyedArray($decoded, 'bulk response');
    }

    /**
     * @return array<mixed, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        try {
            $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('TAAPI v2 returned malformed JSON.', previous: $exception);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('TAAPI v2 response was not an array or object.');
        }

        return $decoded;
    }

    /**
     * @param  array<int, array<string, mixed>>  $candles
     * @return array<string, array<int, mixed>>
     */
    private function candleResult(array $candles, int $results, int $backtrack): array
    {
        $end = max(0, count($candles) - $backtrack);
        $selected = array_slice($candles, max(0, $end - $results), $results);

        $result = [];
        foreach (['timestamp', 'open', 'high', 'low', 'close', 'volume'] as $field) {
            $result[$field] = array_map(
                static fn (array $candle): mixed => $candle[$field] ?? null,
                $selected,
            );
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function legacyResponse(array $data): ResponseInterface
    {
        return new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode($data, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @param  array<mixed, mixed>  $values
     */
    private function requiredString(array $values, string $key, int $constructIndex): string
    {
        $value = $values[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException("TAAPI construct {$constructIndex} requires {$key}.");
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function loggingContext(ApiProperties $properties): array
    {
        $context = [];

        foreach (['account', 'relatable'] as $key) {
            if ($properties->has($key)) {
                $context[$key] = $properties->get($key);
            }
        }

        return $context;
    }

    private function normalizeSymbol(string $symbol): string
    {
        return str_replace(['/', '-'], '', mb_strtoupper($symbol));
    }

    private function integerParameter(mixed $value, int $default, int $minimum): int
    {
        if (is_int($value)) {
            return max($minimum, $value);
        }

        if (is_string($value) && ctype_digit($value)) {
            return max($minimum, (int) $value);
        }

        return $default;
    }

    private function candleProvider(): TaapiV2CandleProvider
    {
        return $this->candles ??= new TaapiV2CandleProvider;
    }

    /**
     * @return array<int, array{timestamp: int, open: int|float, high: int|float, low: int|float, close: int|float, volume: int|float}>
     */
    private function providedCandles(mixed $value, int $constructIndex): array
    {
        if (! is_array($value) || ! array_is_list($value) || $value === []) {
            throw new InvalidArgumentException("TAAPI construct {$constructIndex} supplied an invalid candles list.");
        }

        $candles = [];

        foreach ($value as $candleIndex => $candle) {
            if (! is_array($candle)) {
                throw new InvalidArgumentException(
                    "TAAPI construct {$constructIndex} supplied an invalid candle at index {$candleIndex}.",
                );
            }

            $timestamp = $candle['timestamp'] ?? null;
            if (! is_int($timestamp) && ! (is_string($timestamp) && ctype_digit($timestamp))) {
                throw new InvalidArgumentException(
                    "TAAPI construct {$constructIndex} supplied an invalid candle timestamp at index {$candleIndex}.",
                );
            }

            $candles[] = [
                'timestamp' => (int) $timestamp,
                'open' => $this->providedNumericValue($candle, 'open', $constructIndex, $candleIndex),
                'high' => $this->providedNumericValue($candle, 'high', $constructIndex, $candleIndex),
                'low' => $this->providedNumericValue($candle, 'low', $constructIndex, $candleIndex),
                'close' => $this->providedNumericValue($candle, 'close', $constructIndex, $candleIndex),
                'volume' => $this->providedNumericValue($candle, 'volume', $constructIndex, $candleIndex),
            ];
        }

        return $candles;
    }

    /**
     * @param  array<mixed, mixed>  $candle
     */
    private function providedNumericValue(
        array $candle,
        string $field,
        int $constructIndex,
        int $candleIndex,
    ): int|float {
        $value = $candle[$field] ?? null;

        if (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value))) {
            throw new InvalidArgumentException(
                "TAAPI construct {$constructIndex} supplied an invalid candle {$field} at index {$candleIndex}.",
            );
        }

        $numeric = (float) $value;

        return floor($numeric) === $numeric ? (int) $numeric : $numeric;
    }

    /**
     * @param  array<mixed, mixed>  $values
     * @return array<string, mixed>
     */
    private function stringKeyedArray(array $values, string $context): array
    {
        $normalized = [];

        foreach ($values as $key => $value) {
            if (! is_string($key)) {
                throw new RuntimeException("TAAPI v2 {$context} contained a non-string key.");
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }
}
