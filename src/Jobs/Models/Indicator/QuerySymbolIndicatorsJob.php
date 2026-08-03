<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Models\Indicator;

use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kraite\Core\Abstracts\BaseApiableJob;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Indicator;
use Kraite\Core\Models\IndicatorHistory;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Support\Math;
use Kraite\Core\Support\TaapiMarketDataFreshness;
use Kraite\Core\Support\TaapiNoDataException;
use Kraite\Core\Support\ValueObjects\ApiProperties;
use Throwable;

/**
 * Queries one exchange symbol with every active concluding indicator for one timeframe.
 *
 * Binance Futures is authoritative when its market-data candle is current. Missing,
 * stale, or failed Futures results are filled from Binance Spot only after Spot's
 * own candle proves that response current.
 */
final class QuerySymbolIndicatorsJob extends BaseApiableJob
{
    private const FUTURES_EXCHANGE = 'binancefutures';

    private const SPOT_EXCHANGE = 'binance';

    public int $exchangeSymbolId;

    public string $timeframe;

    public array $previousConclusions;

    private ?string $runIdentifier = null;

    /**
     * @param  array<string, string>  $previousConclusions
     */
    public function __construct(int $exchangeSymbolId, string $timeframe, array $previousConclusions = [])
    {
        $this->exchangeSymbolId = $exchangeSymbolId;
        $this->timeframe = $timeframe;
        $this->previousConclusions = $previousConclusions;
        $this->retries = config()->integer('kraite.indicators.query_retries');
    }

    public function relatable(): ?ExchangeSymbol
    {
        return ExchangeSymbol::find($this->exchangeSymbolId);
    }

    public function assignExceptionHandler(): void
    {
        $this->exceptionHandler = BaseExceptionHandler::make('taapi')
            ->withAccount(Account::admin('taapi'));
    }

    /**
     * @return array{
     *     status: 'fresh'|'unavailable',
     *     stored: int,
     *     errors: array<int, string>,
     *     total_responses: int,
     *     run_timestamp: string,
     *     run_id: string,
     *     fallback_used: bool,
     *     sources: array{binancefutures: array<int, string>, binance: array<int, string>},
     *     unavailable_indicators: array<int, string>,
     *     freshness: array{
     *         binancefutures: array{is_fresh: bool, latest_timestamp: int|null, minimum_timestamp: int|null},
     *         binance: array{is_fresh: bool, latest_timestamp: int|null, minimum_timestamp: int|null}
     *     }
     * }
     */
    public function computeApiable(): array
    {
        $exchangeSymbol = ExchangeSymbol::with('symbol')->findOrFail($this->exchangeSymbolId);
        $apiIndicators = Indicator::query()
            ->active()
            ->fromApi()
            ->concluding()
            ->orderBy('id')
            ->get();
        $runIdentifier = $this->runIdentifier();
        $errors = [];

        if (! in_array($this->timeframe, Kraite::timeframes(), true)) {
            return $this->unavailableResponse(
                runIdentifier: $runIdentifier,
                errors: ["Unsupported indicator timeframe: {$this->timeframe}"],
            );
        }

        if ($apiIndicators->isEmpty()) {
            return $this->unavailableResponse(
                runIdentifier: $runIdentifier,
                errors: ['No API indicators found'],
            );
        }

        $futuresAttempt = $this->requestIndicators(
            exchangeSymbol: $exchangeSymbol,
            indicators: $apiIndicators,
            exchange: self::FUTURES_EXCHANGE,
            errors: $errors,
        );
        $futuresResponse = $futuresAttempt['response'];
        $totalResponses = count($futuresResponse['data'] ?? []);
        $futuresEntries = $this->parseResponseEntries(
            response: $futuresResponse,
            exchangeSymbol: $exchangeSymbol,
            indicators: $apiIndicators,
            expectedExchange: self::FUTURES_EXCHANGE,
            errors: $errors,
        );
        $futuresFreshness = TaapiMarketDataFreshness::fromIndicatorData(
            $futuresEntries,
            $this->timeframe,
        );

        $authoritativeEntries = $futuresFreshness['is_fresh'] ? $futuresEntries : [];
        $missingCanonicals = $this->missingCanonicals($apiIndicators, $authoritativeEntries);
        $fallbackUsed = $missingCanonicals !== [];
        $spotFreshness = TaapiMarketDataFreshness::unavailable();
        $spotAttempt = null;

        if ($fallbackUsed) {
            $spotIndicators = $apiIndicators->filter(
                static fn (Indicator $indicator): bool => $indicator->canonical === 'candle-comparison'
                    || in_array($indicator->canonical, $missingCanonicals, true),
            )->values();
            $spotAttempt = $this->requestIndicators(
                exchangeSymbol: $exchangeSymbol,
                indicators: $spotIndicators,
                exchange: self::SPOT_EXCHANGE,
                errors: $errors,
            );
            $spotResponse = $spotAttempt['response'];
            $totalResponses += count($spotResponse['data'] ?? []);
            $spotEntries = $this->parseResponseEntries(
                response: $spotResponse,
                exchangeSymbol: $exchangeSymbol,
                indicators: $spotIndicators,
                expectedExchange: self::SPOT_EXCHANGE,
                errors: $errors,
            );
            $spotFreshness = TaapiMarketDataFreshness::fromIndicatorData(
                $spotEntries,
                $this->timeframe,
            );

            if ($spotFreshness['is_fresh']) {
                $spotEntries = $this->normalizeSpotEntries($spotEntries, $exchangeSymbol);

                foreach ($missingCanonicals as $canonical) {
                    if (isset($spotEntries[$canonical])) {
                        $authoritativeEntries[$canonical] = $spotEntries[$canonical];
                    }
                }
            }
        }

        if ($fallbackUsed && $this->missingCanonicals($apiIndicators, $authoritativeEntries) !== []) {
            $this->throwOperationalFailure($spotAttempt, $futuresAttempt);
        }

        $records = $this->buildApiRecords(
            entries: $authoritativeEntries,
            indicators: $apiIndicators,
            exchangeSymbol: $exchangeSymbol,
            errors: $errors,
        );
        $unavailableCanonicals = $this->missingCanonicals($apiIndicators, $records);

        if ($unavailableCanonicals === []) {
            $records = [
                ...$records,
                ...$this->buildComputedRecords(
                    apiRecords: $records,
                    exchangeSymbol: $exchangeSymbol,
                    errors: $errors,
                ),
            ];
        }

        $expectedComputedCanonicals = Indicator::query()
            ->active()
            ->computed()
            ->concluding()
            ->orderBy('id')
            ->pluck('canonical')
            ->all();
        $storedCanonicals = array_keys($records);
        $unavailableCanonicals = [
            ...$unavailableCanonicals,
            ...array_values(array_diff($expectedComputedCanonicals, $storedCanonicals)),
        ];

        $this->storeRecords(
            exchangeSymbol: $exchangeSymbol,
            records: $records,
            runIdentifier: $runIdentifier,
        );

        return [
            'status' => $unavailableCanonicals === [] ? 'fresh' : 'unavailable',
            'stored' => count($records),
            'errors' => $errors,
            'total_responses' => $totalResponses,
            'run_timestamp' => $runIdentifier,
            'run_id' => $runIdentifier,
            'fallback_used' => $fallbackUsed,
            'sources' => [
                self::FUTURES_EXCHANGE => $this->recordCanonicalsForSource($records, self::FUTURES_EXCHANGE),
                self::SPOT_EXCHANGE => $this->recordCanonicalsForSource($records, self::SPOT_EXCHANGE),
            ],
            'unavailable_indicators' => array_values(array_unique($unavailableCanonicals)),
            'freshness' => [
                self::FUTURES_EXCHANGE => $futuresFreshness,
                self::SPOT_EXCHANGE => $spotFreshness,
            ],
        ];
    }

    public function resolveException(Throwable $e): void
    {
        // BaseApiableJob and StepDispatcher own failure classification.
    }

    /**
     * @param  Collection<int, Indicator>  $indicators
     * @param  array<int, string>  $errors
     * @return array{
     *     response: array<string, mixed>,
     *     exception: RequestException|null,
     *     is_no_data: bool
     * }
     */
    private function requestIndicators(
        ExchangeSymbol $exchangeSymbol,
        Collection $indicators,
        string $exchange,
        array &$errors,
    ): array {
        if ($indicators->isEmpty()) {
            return [
                'response' => [],
                'exception' => null,
                'is_no_data' => false,
            ];
        }

        $apiProperties = new ApiProperties([
            'constructs' => [
                $this->buildConstruct($exchangeSymbol, $indicators, $exchange),
            ],
            'relatable' => $exchangeSymbol,
        ]);

        try {
            $response = Account::admin('taapi')
                ->withApi()
                ->getBulkIndicatorsValues($apiProperties);
            $decoded = json_decode((string) $response->getBody(), associative: true);

            if (! is_array($decoded)) {
                $errors[] = "{$exchange} request returned invalid JSON";

                return [
                    'response' => [],
                    'exception' => null,
                    'is_no_data' => false,
                ];
            }

            $data = $decoded['data'] ?? null;

            if (! is_array($data) || ! array_is_list($data)) {
                $errors[] = "{$exchange} request returned invalid data list";

                return [
                    'response' => [],
                    'exception' => null,
                    'is_no_data' => false,
                ];
            }

            return [
                'response' => $decoded,
                'exception' => null,
                'is_no_data' => false,
            ];
        } catch (RequestException $exception) {
            $errors[] = sprintf(
                '%s request failed: %s: %s',
                $exchange,
                $exception::class,
                $exception->getMessage(),
            );

            return [
                'response' => [],
                'exception' => $exception,
                'is_no_data' => TaapiNoDataException::matches($exception),
            ];
        }
    }

    /**
     * @param  Collection<int, Indicator>  $indicators
     */
    private function buildConstruct(
        ExchangeSymbol $exchangeSymbol,
        Collection $indicators,
        string $exchange,
    ): array {
        $indicatorParameters = [];

        foreach ($indicators as $indicator) {
            $indicatorClass = $indicator->class;
            $indicatorInstance = new $indicatorClass(
                $exchangeSymbol,
                array_merge($indicator->parameters ?? [], ['interval' => $this->timeframe]),
            );
            $parameters = $indicatorInstance->parameters();
            $parameters['indicator'] = $indicatorInstance->endpoint;
            $parameters['id'] = $this->requestIndicatorId($exchange, $indicator->canonical);
            unset($parameters['endpoint'], $parameters['interval'], $parameters['addResultTimestamp']);
            $indicatorParameters[] = $parameters;
        }

        return [
            'exchange' => $exchange,
            'symbol' => str_replace('-', '/', $this->tokenForExchange($exchangeSymbol, $exchange).'/'.$exchangeSymbol->quote),
            'interval' => $this->timeframe,
            'indicators' => $indicatorParameters,
        ];
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  Collection<int, Indicator>  $indicators
     * @param  array<int, string>  $errors
     * @return array<string, array{id: string, result: mixed, source: string}>
     */
    private function parseResponseEntries(
        array $response,
        ExchangeSymbol $exchangeSymbol,
        Collection $indicators,
        string $expectedExchange,
        array &$errors,
    ): array {
        $entries = [];
        $duplicateCanonicals = [];

        foreach ($response['data'] ?? [] as $entry) {
            if (! is_array($entry)) {
                $errors[] = "{$expectedExchange} returned a non-array indicator entry";

                continue;
            }

            $id = $entry['id'] ?? null;
            $result = $entry['result'] ?? null;

            if (! is_string($id) || $id === '' || ! is_array($result) || $result === []) {
                if (! empty($entry['errors'])) {
                    $errors[] = "{$expectedExchange} indicator error: ".json_encode($entry['errors']);
                } elseif (is_string($id) && $id !== '') {
                    $errors[] = "{$expectedExchange} returned an empty or invalid result for {$id}";
                }

                continue;
            }

            $indicator = $this->matchIndicator(
                id: $id,
                taapiIndicator: $entry['indicator'] ?? null,
                indicators: $indicators,
                exchangeSymbol: $exchangeSymbol,
                expectedExchange: $expectedExchange,
                errors: $errors,
            );

            if ($indicator === null) {
                continue;
            }

            if (isset($duplicateCanonicals[$indicator->canonical])) {
                continue;
            }

            if (isset($entries[$indicator->canonical])) {
                $errors[] = "{$expectedExchange} returned duplicate {$indicator->canonical} results";
                unset($entries[$indicator->canonical]);
                $duplicateCanonicals[$indicator->canonical] = true;

                continue;
            }

            $entries[$indicator->canonical] = [
                'id' => $id,
                'result' => $result,
                'source' => $expectedExchange,
            ];
        }

        return $entries;
    }

    /**
     * @param  Collection<int, Indicator>  $indicators
     * @param  array<int, string>  $errors
     */
    private function matchIndicator(
        string $id,
        mixed $taapiIndicator,
        Collection $indicators,
        ExchangeSymbol $exchangeSymbol,
        string $expectedExchange,
        array &$errors,
    ): ?Indicator {
        $customIndicator = $indicators->first(
            fn (Indicator $indicator): bool => $id === $this->requestIndicatorId(
                $expectedExchange,
                $indicator->canonical,
            ),
        );

        if ($customIndicator instanceof Indicator) {
            return $customIndicator;
        }

        if (str_starts_with($id, 'kraite|')) {
            $errors[] = "{$expectedExchange} returned mismatched construct id {$id}";

            return null;
        }

        $idParts = explode('_', $id);
        $exchange = $idParts[0] ?? null;
        $symbol = $idParts[1] ?? null;
        $interval = $idParts[2] ?? null;
        $expectedSymbol = $this->tokenForExchange($exchangeSymbol, $expectedExchange).'/'.$exchangeSymbol->quote;

        if ($exchange !== $expectedExchange || $symbol !== $expectedSymbol || $interval !== $this->timeframe) {
            $errors[] = "{$expectedExchange} returned mismatched construct id {$id}";

            return null;
        }

        $endpoint = is_string($taapiIndicator) && $taapiIndicator !== ''
            ? $taapiIndicator
            : ($idParts[3] ?? null);
        $period = $endpoint === 'ema' ? ($idParts[4] ?? null) : null;

        $indicator = $indicators->first(static function (Indicator $candidate) use (
            $endpoint,
            $exchangeSymbol,
            $period,
        ): bool {
            $class = $candidate->class;

            if (! class_exists($class)) {
                return false;
            }

            $instance = new $class($exchangeSymbol, ['interval' => '1h']);

            if ($instance->endpoint !== $endpoint) {
                return false;
            }

            return $endpoint !== 'ema'
                || $period === null
                || $candidate->canonical === "ema-{$period}";
        });

        if (! $indicator instanceof Indicator) {
            $errors[] = "{$expectedExchange} indicator not found for {$id}";

            return null;
        }

        return $indicator;
    }

    private function runIdentifier(): string
    {
        return $this->runIdentifier ??= now()->format('Uu').Str::lower(Str::random(16));
    }

    private function requestIndicatorId(string $exchange, string $canonical): string
    {
        return "kraite|{$exchange}|{$this->timeframe}|{$canonical}";
    }

    private function tokenForExchange(ExchangeSymbol $exchangeSymbol, string $exchange): string
    {
        if ($exchange !== self::SPOT_EXCHANGE) {
            return $exchangeSymbol->token;
        }

        $spotToken = $exchangeSymbol->symbol?->token;

        return is_string($spotToken) && $spotToken !== ''
            ? $spotToken
            : $exchangeSymbol->token;
    }

    /**
     * @param  array<string, array{id: string, result: mixed, source: string}>  $entries
     * @return array<string, array{id: string, result: mixed, source: string}>
     */
    private function normalizeSpotEntries(array $entries, ExchangeSymbol $exchangeSymbol): array
    {
        $multiplier = $this->spotPriceMultiplier($exchangeSymbol);

        if ($multiplier === '1') {
            return $entries;
        }

        foreach ($entries as $canonical => &$entry) {
            $entry['result'] = match (true) {
                $canonical === 'candle-comparison' => $this->normalizeCandlePrices($entry['result'], $multiplier),
                str_starts_with($canonical, 'ema-') => $this->normalizeEmaPrices($entry['result'], $multiplier),
                $canonical === 'pivotpoints' => $this->normalizePivotPrices($entry['result'], $multiplier),
                default => $entry['result'],
            };
        }
        unset($entry);

        return $entries;
    }

    private function spotPriceMultiplier(ExchangeSymbol $exchangeSymbol): string
    {
        $futuresToken = Str::upper($exchangeSymbol->token);
        $spotToken = Str::upper($this->tokenForExchange($exchangeSymbol, self::SPOT_EXCHANGE));

        if ($futuresToken === $spotToken) {
            return '1';
        }

        if (preg_match('/^1M(.+)$/', $futuresToken, $matches) === 1 && ($matches[1] ?? null) === $spotToken) {
            return '1000000';
        }

        if (preg_match('/^(\d+)(.+)$/', $futuresToken, $matches) === 1
            && ($matches[2] ?? null) === $spotToken
            && (int) $matches[1] > 0
        ) {
            return $matches[1];
        }

        return '1';
    }

    private function normalizeCandlePrices(mixed $result, string $multiplier): mixed
    {
        if (! is_array($result)) {
            return $result;
        }

        foreach (['open', 'high', 'low', 'close'] as $key) {
            if (array_key_exists($key, $result)) {
                $result[$key] = $this->multiplyNumericValues($result[$key], $multiplier);
            }
        }

        return $result;
    }

    private function normalizeEmaPrices(mixed $result, string $multiplier): mixed
    {
        if (is_array($result) && array_key_exists('value', $result)) {
            $result['value'] = $this->multiplyNumericValues($result['value'], $multiplier);
        }

        return $result;
    }

    private function normalizePivotPrices(mixed $result, string $multiplier): mixed
    {
        if (! is_array($result)) {
            return $result;
        }

        foreach ($result as $key => $value) {
            if (in_array((string) $key, ['r3', 'r2', 'r1', 'p', 's1', 's2', 's3'], true)) {
                $result[$key] = $this->multiplyNumericValues($value, $multiplier);
            } elseif (is_array($value)) {
                $result[$key] = $this->normalizePivotPrices($value, $multiplier);
            }
        }

        return $result;
    }

    private function multiplyNumericValues(mixed $value, string $multiplier): mixed
    {
        if (is_array($value)) {
            return array_map(
                fn (mixed $item): mixed => $this->multiplyNumericValues($item, $multiplier),
                $value,
            );
        }

        return is_numeric($value) ? Math::mul($value, $multiplier) : $value;
    }

    /**
     * @param  array{response: array<string, mixed>, exception: RequestException|null, is_no_data: bool}|null  $spotAttempt
     * @param  array{response: array<string, mixed>, exception: RequestException|null, is_no_data: bool}  $futuresAttempt
     */
    private function throwOperationalFailure(?array $spotAttempt, array $futuresAttempt): void
    {
        foreach ([$spotAttempt, $futuresAttempt] as $attempt) {
            if (($attempt['exception'] ?? null) instanceof RequestException
                && ($attempt['is_no_data'] ?? false) === false
            ) {
                throw $attempt['exception'];
            }
        }
    }

    /**
     * @param  Collection<int, Indicator>  $indicators
     * @param  array<string, mixed>  $entries
     * @return array<int, string>
     */
    private function missingCanonicals(Collection $indicators, array $entries): array
    {
        return $indicators
            ->pluck('canonical')
            ->diff(array_keys($entries))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, array{id: string, result: mixed, source: string}>  $entries
     * @param  Collection<int, Indicator>  $indicators
     * @param  array<int, string>  $errors
     * @return array<string, array{
     *     indicator: Indicator,
     *     construct_id: string,
     *     data: mixed,
     *     conclusion: mixed,
     *     source: string
     * }>
     */
    private function buildApiRecords(
        array $entries,
        Collection $indicators,
        ExchangeSymbol $exchangeSymbol,
        array &$errors,
    ): array {
        $records = [];

        foreach ($entries as $canonical => $entry) {
            $indicator = $indicators->firstWhere('canonical', $canonical);

            if (! $indicator instanceof Indicator) {
                continue;
            }

            try {
                $class = $indicator->class;
                $instance = new $class($exchangeSymbol, ['interval' => $this->timeframe]);
                $instance->load($entry['result']);
                $records[$canonical] = [
                    'indicator' => $indicator,
                    'construct_id' => $entry['id'],
                    'data' => $entry['result'],
                    'conclusion' => $instance->conclusion(),
                    'source' => $entry['source'],
                ];
            } catch (Throwable $exception) {
                $errors[] = "Indicator {$canonical} failed: {$exception->getMessage()}";
            }
        }

        return $records;
    }

    /**
     * @param  array<string, array{
     *     indicator: Indicator,
     *     construct_id: string,
     *     data: mixed,
     *     conclusion: mixed,
     *     source: string
     * }>  $apiRecords
     * @param  array<int, string>  $errors
     * @return array<string, array{
     *     indicator: Indicator,
     *     construct_id: string,
     *     data: mixed,
     *     conclusion: mixed,
     *     source: string
     * }>
     */
    private function buildComputedRecords(
        array $apiRecords,
        ExchangeSymbol $exchangeSymbol,
        array &$errors,
    ): array {
        $indicatorData = collect($apiRecords)
            ->map(static fn (array $record): array => [
                'result' => $record['data'],
                'conclusion' => $record['conclusion'],
            ])
            ->all();
        $records = [];
        $computedIndicators = Indicator::query()
            ->active()
            ->computed()
            ->concluding()
            ->orderBy('id')
            ->get();

        foreach ($computedIndicators as $indicator) {
            try {
                $class = $indicator->class;

                if (! class_exists($class)) {
                    $errors[] = "Computed indicator class not found: {$class}";

                    continue;
                }

                $instance = new $class($exchangeSymbol, ['interval' => $this->timeframe]);
                $instance->load($indicatorData);
                $records[$indicator->canonical] = [
                    'indicator' => $indicator,
                    'construct_id' => "computed_{$indicator->canonical}_{$this->timeframe}",
                    'data' => $indicatorData,
                    'conclusion' => $instance->conclusion(),
                    'source' => 'computed',
                ];
            } catch (Throwable $exception) {
                $errors[] = "Computed indicator {$indicator->canonical} failed: {$exception->getMessage()}";
            }
        }

        return $records;
    }

    /**
     * @param  array<string, array{
     *     indicator: Indicator,
     *     construct_id: string,
     *     data: mixed,
     *     conclusion: mixed,
     *     source: string
     * }>  $records
     */
    private function storeRecords(
        ExchangeSymbol $exchangeSymbol,
        array $records,
        string $runIdentifier,
    ): void {
        if ($records === []) {
            return;
        }

        $now = now();
        $rows = [];

        foreach ($records as $record) {
            $constructId = $record['source'] === 'computed'
                ? "{$record['construct_id']}_{$runIdentifier}"
                : $record['construct_id'];
            $rows[] = [
                'exchange_symbol_id' => $exchangeSymbol->id,
                'indicator_id' => $record['indicator']->id,
                'taapi_construct_id' => $constructId,
                'timeframe' => $this->timeframe,
                'timestamp' => $runIdentifier,
                'data' => json_encode($record['data'], JSON_THROW_ON_ERROR),
                'conclusion' => $record['conclusion'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::transaction(static function () use ($rows): void {
            IndicatorHistory::query()->upsert(
                $rows,
                ['exchange_symbol_id', 'indicator_id', 'timeframe', 'timestamp'],
                ['taapi_construct_id', 'data', 'conclusion', 'updated_at'],
            );
        });
    }

    /**
     * @param  array<string, array{source: string}>  $records
     * @return array<int, string>
     */
    private function recordCanonicalsForSource(array $records, string $source): array
    {
        return collect($records)
            ->filter(static fn (array $record): bool => $record['source'] === $source)
            ->keys()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $errors
     * @return array{
     *     status: 'unavailable',
     *     stored: 0,
     *     errors: array<int, string>,
     *     total_responses: 0,
     *     run_timestamp: string,
     *     run_id: string,
     *     fallback_used: bool,
     *     sources: array{binancefutures: array<int, string>, binance: array<int, string>},
     *     unavailable_indicators: array<int, string>,
     *     freshness: array{
     *         binancefutures: array{is_fresh: bool, latest_timestamp: int|null, minimum_timestamp: int|null},
     *         binance: array{is_fresh: bool, latest_timestamp: int|null, minimum_timestamp: int|null}
     *     }
     * }
     */
    private function unavailableResponse(string $runIdentifier, array $errors): array
    {
        return [
            'status' => 'unavailable',
            'stored' => 0,
            'errors' => $errors,
            'total_responses' => 0,
            'run_timestamp' => $runIdentifier,
            'run_id' => $runIdentifier,
            'fallback_used' => false,
            'sources' => [
                self::FUTURES_EXCHANGE => [],
                self::SPOT_EXCHANGE => [],
            ],
            'unavailable_indicators' => [],
            'freshness' => [
                self::FUTURES_EXCHANGE => TaapiMarketDataFreshness::unavailable(),
                self::SPOT_EXCHANGE => TaapiMarketDataFreshness::unavailable(),
            ],
        ];
    }
}
