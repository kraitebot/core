<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Models\ExchangeSymbol;

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Kraite\Core\Abstracts\BaseApiableJob;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\Candle;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Support\Proxies\ApiDataMapperProxy;
use Kraite\Core\Support\Proxies\ApiRESTProxy;
use Kraite\Core\Support\ValueObjects\ApiCredentials;
use Throwable;

/**
 * FetchKlinesJob
 *
 * Fetches candlestick (OHLCV) data for a single exchange symbol via REST API.
 * Stores results in the candles table using upsert with advisory locks.
 *
 * This job replaces TAAPI-based candle fetching with direct exchange API calls.
 * All exchange endpoints are public (no authentication required).
 */
final class FetchKlinesJob extends BaseApiableJob
{
    public ExchangeSymbol $exchangeSymbol;

    public string $timeframe;

    public int $limit;

    /**
     * @param  int  $exchangeSymbolId  The exchange symbol to fetch klines for
     * @param  string  $timeframe  The candle timeframe (e.g., '5m', '1h', '4h', '1d')
     * @param  int  $limit  Number of candles to fetch (default: 1)
     */
    public function __construct(
        int $exchangeSymbolId,
        string $timeframe = '5m',
        int $limit = 1
    ) {
        $this->exchangeSymbol = ExchangeSymbol::with('apiSystem')->findOrFail($exchangeSymbolId);
        $this->timeframe = $timeframe;
        $this->limit = $limit;
        $this->retries = 10;
    }

    public function assignExceptionHandler(): void
    {
        $canonical = $this->exchangeSymbol->apiSystem->canonical;
        $this->exceptionHandler = BaseExceptionHandler::make($canonical)
            ->withAccount(Account::admin($canonical));
    }

    public function relatable()
    {
        return $this->exchangeSymbol;
    }

    public function computeApiable()
    {
        $canonical = $this->exchangeSymbol->apiSystem->canonical;

        // Small jitter to prevent concurrent request storms
        Sleep::for(random_int(50, 300))->milliseconds();

        // Get the data mapper for this exchange
        $mapper = new ApiDataMapperProxy($canonical);

        // Prepare API properties using the DataMapper
        $properties = $mapper->prepareQueryKlinesProperties(
            $this->exchangeSymbol,
            $this->timeframe,
            null, // startTime
            null, // endTime
            $this->limit
        );

        // Get REST API client (empty credentials for public endpoint)
        $api = new ApiRESTProxy($canonical, new ApiCredentials([]));

        try {
            // Call the exchange API
            $response = $api->getKlines($properties);
        } catch (Throwable $e) {
            // If the exchange reports the symbol is gone (Binance -1121, Bybit
            // 10001/"not supported", KuCoin 200003, BitGet 40309) we mark it
            // for delisting and settle the step gracefully. Any other error
            // bubbles up to the normal API exception handling pipeline.
            if ($this->exceptionHandler->isSymbolDelisted($e)) {
                return $this->handleSymbolDelisted();
            }

            throw $e;
        }

        // Resolve the response using the DataMapper
        $klines = $mapper->resolveQueryKlinesResponse($response);

        if (empty($klines)) {
            return [
                'exchange_symbol_id' => $this->exchangeSymbol->id,
                'stored' => 0,
                'message' => 'No klines returned from API',
            ];
        }

        // Store the klines
        $storedCount = $this->storeKlines($klines);

        return [
            'exchange_symbol_id' => $this->exchangeSymbol->id,
            'symbol' => $this->exchangeSymbol->parsed_trading_pair,
            'timeframe' => $this->timeframe,
            'fetched' => count($klines),
            'stored' => $storedCount,
        ];
    }

    /**
     * Persist the `is_marked_for_delisting` flag on the ExchangeSymbol and
     * return a terminal "delisted" payload so the step completes cleanly.
     *
     * Complements the proactive detection in ExchangeSymbolObserver +
     * *TradingMapper::isNowDelisted(): that path fires when the exchange
     * announces a delivery date during market-data refresh, while this one
     * handles symbols that simply disappear from the exchange and only
     * surface the fact through runtime errors.
     *
     * @return array<string, mixed>
     */
    private function handleSymbolDelisted(): array
    {
        DB::transaction(function (): void {
            $symbol = ExchangeSymbol::query()
                ->whereKey($this->exchangeSymbol->id)
                ->lockForUpdate()
                ->first();

            if ($symbol !== null && ! $symbol->is_marked_for_delisting) {
                $symbol->update(['is_marked_for_delisting' => true]);
            }
        });

        $canonical = $this->exchangeSymbol->apiSystem->canonical;

        return [
            'exchange_symbol_id' => $this->exchangeSymbol->id,
            'symbol' => $this->exchangeSymbol->parsed_trading_pair,
            'timeframe' => $this->timeframe,
            'delisted' => true,
            'message' => "Symbol marked for delisting after {$canonical} reported it as removed",
        ];
    }

    /**
     * Store klines in the candles table using upsert with advisory lock.
     *
     * @param  array<int, array{timestamp: int, open: string, high: string, low: string, close: string, volume: string}>  $klines
     */
    private function storeKlines(array $klines): int
    {
        $now = now();
        $buffer = [];

        foreach ($klines as $kline) {
            // Normalize epoch to seconds (handle both ms and sec formats)
            $epochSec = $this->normalizeEpochToSeconds($kline['timestamp']);

            // Convert to SQL datetime in UTC
            $candleTimeUtc = Carbon::createFromTimestampUTC($epochSec);
            $candleTimeLocal = $candleTimeUtc->copy()->setTimezone(config('app.timezone'));

            $buffer[] = [
                'exchange_symbol_id' => $this->exchangeSymbol->id,
                'timeframe' => $this->timeframe,
                'timestamp' => $epochSec,
                'candle_time_utc' => $candleTimeUtc->format('Y-m-d H:i:s'),
                'candle_time_local' => $candleTimeLocal->format('Y-m-d H:i:s'),
                'open' => $kline['open'],
                'high' => $kline['high'],
                'low' => $kline['low'],
                'close' => $kline['close'],
                'volume' => $kline['volume'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (empty($buffer)) {
            return 0;
        }

        // Upsert with advisory lock to prevent deadlocks
        $this->upsertWithLock($buffer);

        return count($buffer);
    }

    /**
     * Normalize epoch to seconds (handles both seconds and milliseconds).
     */
    private function normalizeEpochToSeconds(int $epoch): int
    {
        // If >= 10^12 (year 2001+ in ms), assume milliseconds
        if ($epoch >= 1_000_000_000_000) {
            return intdiv($epoch, 1000);
        }

        return $epoch;
    }

    /**
     * Upsert candles with advisory lock to prevent deadlocks.
     * Uses MySQL GET_LOCK to serialize upserts per symbol/timeframe.
     *
     * Short 2s lock timeout on purpose: overlapping bulk-fetch crons
     * (the 5-minute `--only-active-positions` tick + the hourly per-
     * timeframe ticks) can fan out the SAME (symbol, timeframe) fetch
     * into concurrent blocks. Each instance pulls the identical recent-
     * candle window from the exchange and writes the identical upsert,
     * so a contended loser can safely skip without data loss — the
     * winner's write is indistinguishable from ours. A long wait here
     * used to trip the 5s slow-query alarm and spam pushover at :15 /
     * :05 / :00 under normal cron overlap.
     *
     * @param  array<int, array<string, mixed>>  $buffer
     */
    private function upsertWithLock(array $buffer, int $maxAttempts = 5): void
    {
        $lockKey = "candles_{$this->exchangeSymbol->id}_{$this->timeframe}";
        $lockTimeout = 2;
        $lockAcquired = false;

        try {
            $result = DB::selectOne('SELECT GET_LOCK(?, ?) as lock_result', [$lockKey, $lockTimeout]);

            if (! $result || $result->lock_result !== 1) {
                // Another worker is already running the identical upsert
                // for this (symbol, timeframe). Skip silently — the
                // winner's transaction will persist the same candles we
                // would have written. Logged at debug level so the
                // audit trail exists without polluting the jobs log.
                Log::channel('jobs')->debug(
                    '[FETCH-KLINES] Skipping upsert — another worker holds the advisory lock',
                    ['lock_key' => $lockKey, 'timeout_seconds' => $lockTimeout, 'buffer_size' => count($buffer)]
                );

                return;
            }

            $lockAcquired = true;

            $attempt = 0;

            while ($attempt < $maxAttempts) {
                try {
                    DB::transaction(static function () use ($buffer) {
                        Candle::query()->upsert(
                            $buffer,
                            ['exchange_symbol_id', 'timeframe', 'timestamp'],
                            ['open', 'high', 'low', 'close', 'volume', 'candle_time_utc', 'candle_time_local', 'updated_at']
                        );
                    });

                    return;
                } catch (QueryException $e) {
                    if ($e->getCode() === '40001' || str_contains(haystack: $e->getMessage(), needle: 'Deadlock')) {
                        $attempt++;

                        if ($attempt >= $maxAttempts) {
                            throw $e;
                        }

                        $backoffMs = 100 * (2 ** ($attempt - 1));
                        Sleep::for($backoffMs)->milliseconds();

                        continue;
                    }

                    throw $e;
                }
            }
        } finally {
            if ($lockAcquired) {
                DB::selectOne('SELECT RELEASE_LOCK(?) as release_result', [$lockKey]);
            }
        }
    }
}
