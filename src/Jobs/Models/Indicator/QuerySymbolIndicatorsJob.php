<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Models\Indicator;

use Exception;
use Illuminate\Support\Collection;
use Kraite\Core\Abstracts\BaseApiableJob;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Indicator;
use Kraite\Core\Models\IndicatorHistory;
use Kraite\Core\Support\ValueObjects\ApiProperties;
use Throwable;

/**
 * QuerySymbolIndicatorsJob
 *
 * Queries a SINGLE exchange symbol with ALL indicators for a SINGLE timeframe.
 * Part of atomic per-symbol workflow for progressive indicator analysis.
 * Stores results in indicator_histories table.
 */
final class QuerySymbolIndicatorsJob extends BaseApiableJob
{
    public int $exchangeSymbolId;

    public string $timeframe;

    public array $previousConclusions;

    /**
     * @param  int  $exchangeSymbolId  Single symbol to query
     * @param  string  $timeframe  The timeframe to query (e.g., '1h', '4h')
     * @param  array  $previousConclusions  Conclusions from previous timeframes for path consistency
     */
    public function __construct(int $exchangeSymbolId, string $timeframe, array $previousConclusions = [])
    {
        $this->exchangeSymbolId = $exchangeSymbolId;
        $this->timeframe = $timeframe;
        $this->previousConclusions = $previousConclusions;
        $this->retries = 150;
    }

    public function relatable()
    {
        return ExchangeSymbol::find($this->exchangeSymbolId);
    }

    public function assignExceptionHandler(): void
    {
        $this->exceptionHandler = BaseExceptionHandler::make('taapi')
            ->withAccount(Account::admin('taapi'));
    }

    public function computeApiable()
    {
        // Load the exchange symbol
        $exchangeSymbol = ExchangeSymbol::findOrFail($this->exchangeSymbolId);

        // Load active conclude-indicators indicators
        $indicators = Indicator::query()
            ->where('is_active', true)
            ->where('is_computed', false)
            ->where('type', 'conclude-indicators')
            ->get();

        if ($indicators->isEmpty()) {
            return ['stored' => 0, 'errors' => ['No indicators found']];
        }

        // Build Taapi bulk API construct for this single symbol
        $construct = $this->buildConstruct($exchangeSymbol, $indicators);

        // Use proper API infrastructure
        $apiAccount = Account::admin('taapi');

        // Build API properties for bulk request (single construct)
        $apiProperties = new ApiProperties([
            'constructs' => [$construct],
            'relatable' => $exchangeSymbol,
        ]);

        // Make the API call using proper infrastructure
        $guzzleResponse = $apiAccount->withApi()->getBulkIndicatorsValues($apiProperties);

        // Parse Guzzle response to array
        $response = json_decode((string) $guzzleResponse->getBody(), associative: true);

        // Parse and store results
        return $this->parseAndStoreResults($response, $exchangeSymbol, $indicators);
    }

    public function resolveException(Throwable $e)
    {
        // Engine::notifyAdmins(
        //     message: '[Symbol:'.$this->exchangeSymbolId.' | Timeframe:'.$this->timeframe.'] Query error - '.$e->getMessage(),
        //     title: '['.class_basename(self::class).'] - Error',
        //     deliveryGroup: 'exceptions'
        // );
    }

    /**
     * Build Taapi bulk API construct for a single symbol.
     */
    private function buildConstruct(ExchangeSymbol $exchangeSymbol, Collection $indicators): array
    {
        $indicatorsArray = [];

        // Build ALL indicator parameter sets for this symbol
        foreach ($indicators as $indicator) {
            $indicatorClass = $indicator->class;
            $indicatorInstance = new $indicatorClass($exchangeSymbol, array_merge($indicator->parameters ?? [], ['interval' => $this->timeframe]));

            $params = $indicatorInstance->parameters();
            $params['indicator'] = $indicatorInstance->endpoint;

            // Remove Taapi-internal flags
            unset($params['endpoint'], $params['interval'], $params['addResultTimestamp']);

            $indicatorsArray[] = $params;
        }

        return [
            'exchange' => 'binancefutures',
            'symbol' => str_replace(search: '-', replace: '/', subject: $exchangeSymbol->token.'/'.$exchangeSymbol->quote),
            'interval' => $this->timeframe,
            'indicators' => $indicatorsArray,
        ];
    }

    /**
     * Parse Taapi bulk response and upsert to indicator_histories.
     */
    private function parseAndStoreResults(array $response, ExchangeSymbol $exchangeSymbol, Collection $indicators): array
    {
        $data = $response['data'] ?? [];
        $stored = 0;
        $errors = [];
        $now = now();
        $indicatorData = [];

        foreach ($data as $entry) {
            $id = $entry['id'] ?? null;
            $result = $entry['result'] ?? null;
            $entryErrors = $entry['errors'] ?? [];
            $taapiIndicator = $entry['indicator'] ?? null;

            if (! $id || ! $result) {
                if (! empty($entryErrors)) {
                    $errors[] = "ID: {$id} - Errors: ".json_encode($entryErrors);
                }

                continue;
            }

            // Parse ID: "binance_BTC/USDT_1h_ema_40_2_1"
            try {
                $idParts = explode('_', $id);
                $exchange = $idParts[0] ?? null;
                $symbol = $idParts[1] ?? null;
                $interval = $idParts[2] ?? null;

                if (! $exchange || ! $symbol || ! $interval) {
                    throw new Exception("Invalid ID format: {$id}");
                }

                // Match indicator by endpoint and optional period parameter
                $indicatorEndpoint = $taapiIndicator ?? $idParts[3] ?? null;
                $periodParam = null;

                // For EMA, the period is at index 4
                // Format: binancefutures_BTC/USDT_5m_ema_40_2_1 (period_results_backtrack)
                if ($indicatorEndpoint === 'ema') {
                    $periodParam = $idParts[4] ?? null;
                }

                // Try to match indicator
                $indicator = $indicators->first(static function ($ind) use ($indicatorEndpoint, $periodParam, $exchangeSymbol) {
                    $indClass = $ind->class;
                    if (! class_exists($indClass)) {
                        return false;
                    }

                    $tempInstance = new $indClass($exchangeSymbol, ['interval' => '1h']);
                    if ($tempInstance->endpoint !== $indicatorEndpoint) {
                        return false;
                    }

                    // For EMA, also match period
                    if ($indicatorEndpoint === 'ema' && $periodParam) {
                        $expectedCanonical = "ema-{$periodParam}";

                        return $ind->canonical === $expectedCanonical;
                    }

                    return true;
                });

                if (! $indicator) {
                    $errors[] = "Indicator not found: {$indicatorEndpoint}".($periodParam ? "-{$periodParam}" : '');

                    continue;
                }

                // Use current timestamp
                $timestamp = now()->timestamp;

                // Instantiate indicator to call conclusion()
                $indicatorClass = $indicator->class;
                $indicatorInstance = new $indicatorClass($exchangeSymbol, ['interval' => $interval]);
                $indicatorInstance->load($result);

                $conclusion = $indicatorInstance->conclusion();

                // Store indicator data for computed indicators
                $indicatorData[$indicator->canonical] = [
                    'result' => $result,
                    'conclusion' => $conclusion,
                ];

                // Upsert to indicator_histories
                IndicatorHistory::query()->upsert(
                    [
                        [
                            'exchange_symbol_id' => $exchangeSymbol->id,
                            'indicator_id' => $indicator->id,
                            'taapi_construct_id' => $id,
                            'timeframe' => $interval,
                            'timestamp' => (string) $timestamp,
                            'data' => json_encode($result),
                            'conclusion' => $conclusion,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ],
                    ],
                    // Unique constraint
                    ['exchange_symbol_id', 'indicator_id', 'timeframe', 'timestamp'],
                    // Update on conflict
                    ['taapi_construct_id', 'data', 'conclusion', 'updated_at']
                );

                $stored++;
            } catch (Throwable $e) {
                $errors[] = "Parse error for {$id}: {$e->getMessage()}";
            }
        }

        // Process computed (non-apiable) indicators
        $computedIndicators = Indicator::query()
            ->where('is_active', true)
            ->where('is_computed', true)
            ->where('type', 'conclude-indicators')
            ->get();

        foreach ($computedIndicators as $computedIndicator) {
            try {
                $indicatorClass = $computedIndicator->class;
                if (! class_exists($indicatorClass)) {
                    $errors[] = "Computed indicator class not found: {$indicatorClass}";

                    continue;
                }

                // Instantiate computed indicator and load all indicator data
                $computedInstance = new $indicatorClass($exchangeSymbol, ['interval' => $this->timeframe]);
                $computedInstance->load($indicatorData);

                $conclusion = $computedInstance->conclusion();
                $timestamp = now()->timestamp;

                // Store computed indicator conclusion
                IndicatorHistory::query()->upsert(
                    [
                        [
                            'exchange_symbol_id' => $exchangeSymbol->id,
                            'indicator_id' => $computedIndicator->id,
                            'taapi_construct_id' => "computed_{$computedIndicator->canonical}_{$this->timeframe}_{$timestamp}",
                            'timeframe' => $this->timeframe,
                            'timestamp' => (string) $timestamp,
                            'data' => json_encode($indicatorData),
                            'conclusion' => $conclusion,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ],
                    ],
                    // Unique constraint
                    ['exchange_symbol_id', 'indicator_id', 'timeframe', 'timestamp'],
                    // Update on conflict
                    ['taapi_construct_id', 'data', 'conclusion', 'updated_at']
                );

                $stored++;
            } catch (Throwable $e) {
                $errors[] = "Computed indicator error for {$computedIndicator->canonical}: {$e->getMessage()}";
            }
        }

        return [
            'stored' => $stored,
            'errors' => $errors,
            'total_responses' => count($data),
        ];
    }
}
