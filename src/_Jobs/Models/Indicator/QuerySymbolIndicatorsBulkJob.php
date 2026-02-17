<?php

declare(strict_types=1);

namespace Kraite\Core\_Jobs\Models\Indicator;

use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Sleep;
use Kraite\Core\Abstracts\BaseApiableJob;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\_Jobs\Models\ExchangeSymbol\ConcludeSymbolDirectionAtTimeframeJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Indicator;
use Kraite\Core\Models\IndicatorHistory;
use StepDispatcher\Models\Step;
use Kraite\Core\Support\ValueObjects\ApiProperties;
use Throwable;

use function count;

/**
 * QuerySymbolIndicatorsBulkJob
 *
 * Batch-queries indicator data for multiple exchange symbols in a single TAAPI API call
 * using the /bulk endpoint with multiple constructs.
 *
 * This reduces API calls from 1-per-symbol to 1-per-chunk, respecting TAAPI's
 * construct limits per plan (Pro: 3, Expert: 10, Max: 20).
 *
 * After storing results, creates individual ConcludeSymbolDirectionAtTimeframeJob steps
 * for each symbol with round-robin group assignment.
 */
final class QuerySymbolIndicatorsBulkJob extends BaseApiableJob
{
    /** @var int[] Array of exchange symbol IDs to process */
    public array $exchangeSymbolIds;

    /** The timeframe for this batch (e.g., '1h', '4h', '1d') */
    public string $timeframe;

    /** Whether to clean up indicator histories after conclusion */
    public bool $shouldCleanup;

    /** @var Collection<int, ExchangeSymbol> Loaded exchange symbols keyed by ID */
    private Collection $exchangeSymbols;

    /** @var Collection<int, Indicator> Active non-computed indicators */
    private Collection $indicators;

    /**
     * @param  int[]  $exchangeSymbolIds  Array of exchange symbol IDs to fetch indicators for
     * @param  string  $timeframe  The indicator timeframe (e.g., '1h', '4h', '1d')
     * @param  bool  $shouldCleanup  Whether to cleanup indicator histories after conclusion
     */
    public function __construct(
        array $exchangeSymbolIds,
        string $timeframe,
        bool $shouldCleanup = true
    ) {
        $this->exchangeSymbolIds = $exchangeSymbolIds;
        $this->timeframe = $timeframe;
        $this->shouldCleanup = $shouldCleanup;
        $this->retries = 150;

        // Load exchange symbols with relationships for construct building
        $this->exchangeSymbols = ExchangeSymbol::query()
            ->with(['symbol', 'apiSystem'])
            ->whereIn('id', $exchangeSymbolIds)
            ->get()
            ->keyBy('id');

        // Load active non-computed indicators
        $this->indicators = Indicator::query()
            ->where('is_active', true)
            ->where('is_computed', false)
            ->where('type', 'conclude-indicators')
            ->get();
    }

    public function assignExceptionHandler(): void
    {
        $this->exceptionHandler = BaseExceptionHandler::make('taapi')
            ->withAccount(Account::admin('taapi'));
    }

    public function relatable()
    {
        // Return the first exchange symbol for logging/tracking purposes
        return $this->exchangeSymbols->first();
    }

    public function computeApiable()
    {
        if ($this->exchangeSymbols->isEmpty()) {
            return ['stored' => 0, 'errors' => ['No exchange symbols found']];
        }

        if ($this->indicators->isEmpty()) {
            return ['stored' => 0, 'errors' => ['No indicators found']];
        }

        // Significant jitter to prevent concurrent request storms
        Sleep::for(random_int(500, 2000))->milliseconds();

        // Build bulk request constructs (one per symbol)
        $constructs = $this->buildConstructs();

        // Make bulk API call
        $response = $this->callBulkApi($constructs);

        // Parse and store results for all symbols
        $result = $this->parseAndStoreResults($response);

        // Create Conclude steps for each symbol
        $this->createConcludeSteps();

        return $result;
    }

    /**
     * Build TAAPI bulk API constructs array.
     * Each construct represents one symbol with all its indicator requests.
     */
    private function buildConstructs(): array
    {
        $constructs = [];

        foreach ($this->exchangeSymbols as $exchangeSymbol) {
            $indicatorsArray = [];

            // Build ALL indicator parameter sets for this symbol
            foreach ($this->indicators as $indicator) {
                $indicatorClass = $indicator->class;
                $indicatorInstance = new $indicatorClass($exchangeSymbol, array_merge($indicator->parameters ?? [], ['interval' => $this->timeframe]));

                $params = $indicatorInstance->parameters();
                $params['indicator'] = $indicatorInstance->endpoint;

                // Remove Taapi-internal flags
                unset($params['endpoint'], $params['interval'], $params['addResultTimestamp']);

                $indicatorsArray[] = $params;
            }

            $constructs[] = [
                'id' => (string) $exchangeSymbol->id,
                'exchange' => 'binancefutures',
                'symbol' => $this->buildSymbolForTaapi($exchangeSymbol),
                'interval' => $this->timeframe,
                'indicators' => $indicatorsArray,
            ];
        }

        return $constructs;
    }

    /**
     * Build the symbol string for TAAPI API (e.g., "BTC/USDT").
     * Uses token and quote columns directly from exchange_symbols.
     */
    private function buildSymbolForTaapi(ExchangeSymbol $exchangeSymbol): string
    {
        return "{$exchangeSymbol->token}/{$exchangeSymbol->quote}";
    }

    /**
     * Call TAAPI's bulk API with the constructs payload.
     */
    private function callBulkApi(array $constructs): array
    {
        $apiAccount = Account::admin('taapi');

        // Build API properties for bulk request
        $payload = [
            'constructs' => $constructs,
        ];

        // Link API request log to the Step if running via Step dispatcher
        if (isset($this->step)) {
            $payload['relatable'] = $this->step;
        }

        $apiProperties = new ApiProperties($payload);

        // Make the API call using proper infrastructure
        $guzzleResponse = $apiAccount->withApi()->getBulkIndicatorsValues($apiProperties);

        return json_decode((string) $guzzleResponse->getBody(), associative: true);
    }

    /**
     * Parse TAAPI bulk response and store in indicator_histories for all symbols.
     *
     * Response format:
     * {
     *   "data": [
     *     {
     *       "id": "binancefutures_BTC/USDT_1h_ema_40_2_1",
     *       "indicator": "ema",
     *       "result": {...},
     *       "errors": []
     *     },
     *     ...
     *   ]
     * }
     */
    private function parseAndStoreResults(array $response): array
    {
        $data = $response['data'] ?? [];
        $stored = 0;
        $errors = [];
        $now = now();

        // Track indicator data per symbol for computed indicators
        $symbolIndicatorData = [];
        foreach ($this->exchangeSymbolIds as $symbolId) {
            $symbolIndicatorData[$symbolId] = [];
        }

        foreach ($data as $entry) {
            $id = $entry['id'] ?? null;
            $result = $entry['result'] ?? null;
            $entryErrors = $entry['errors'] ?? [];
            $taapiIndicator = $entry['indicator'] ?? null;

            if (! $id || $result === null) {
                if (! empty($entryErrors)) {
                    $errors[] = "ID: {$id} - Errors: ".json_encode($entryErrors);
                }

                continue;
            }

            try {
                // Parse ID: "binancefutures_BTC/USDT_1h_ema_40_2_1"
                $idParts = explode('_', $id);
                $exchange = $idParts[0] ?? null;
                $symbol = $idParts[1] ?? null;
                $interval = $idParts[2] ?? null;

                if (! $exchange || ! $symbol || ! $interval) {
                    throw new Exception("Invalid ID format: {$id}");
                }

                // Find matching exchange symbol
                $exchangeSymbol = $this->findExchangeSymbolFromResponse($exchange, $symbol);
                if (! $exchangeSymbol) {
                    $errors[] = "Exchange symbol not found for: {$exchange}_{$symbol}";

                    continue;
                }

                // Match indicator by endpoint and optional period parameter
                $indicatorEndpoint = $taapiIndicator ?? $idParts[3] ?? null;
                $periodParam = null;

                // Check if this is an EMA with period in the ID (position 4)
                if ($indicatorEndpoint === 'ema' && isset($idParts[4]) && is_numeric($idParts[4])) {
                    $periodParam = $idParts[4];
                }

                // Try to match indicator
                $indicator = $this->indicators->first(static function ($ind) use ($indicatorEndpoint, $periodParam, $exchangeSymbol) {
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
                $symbolIndicatorData[$exchangeSymbol->id][$indicator->canonical] = [
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

        // Process computed (non-apiable) indicators for each symbol
        $computedIndicators = Indicator::query()
            ->where('is_active', true)
            ->where('is_computed', true)
            ->where('type', 'conclude-indicators')
            ->get();

        foreach ($this->exchangeSymbols as $exchangeSymbol) {
            $indicatorData = $symbolIndicatorData[$exchangeSymbol->id] ?? [];

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
                    $errors[] = "Computed indicator error for {$computedIndicator->canonical} (symbol {$exchangeSymbol->id}): {$e->getMessage()}";
                }
            }
        }

        return [
            'stored' => $stored,
            'errors' => $errors,
            'total_responses' => count($data),
            'symbols_processed' => count($this->exchangeSymbolIds),
        ];
    }

    /**
     * Find the exchange symbol from TAAPI response exchange and symbol.
     */
    private function findExchangeSymbolFromResponse(string $exchange, string $symbol): ?ExchangeSymbol
    {
        return $this->exchangeSymbols->first(function (ExchangeSymbol $es) use ($symbol, $exchange) {
            // Build the TAAPI symbol format using the same method as request building
            $taapiSymbol = $this->buildSymbolForTaapi($es);

            // TAAPI always uses 'binancefutures' as exchange
            return $taapiSymbol === $symbol && $exchange === 'binancefutures';
        });
    }

    /**
     * Create individual ConcludeSymbolDirectionAtTimeframeJob steps for each symbol.
     * Step properties (block_uuid, group, index) are auto-assigned by StepObserver
     * to match the pattern used by StoreCandlesCommand (which works correctly).
     */
    private function createConcludeSteps(): void
    {
        foreach ($this->exchangeSymbolIds as $symbolId) {
            Step::create([
                'class' => ConcludeSymbolDirectionAtTimeframeJob::class,
                'arguments' => [
                    'exchangeSymbolId' => $symbolId,
                    'timeframe' => $this->timeframe,
                    'previousConclusions' => [],
                    'shouldCleanup' => $this->shouldCleanup,
                ],
            ]);
        }
    }
}
