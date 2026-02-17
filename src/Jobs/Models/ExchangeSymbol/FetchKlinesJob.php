<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Models\ExchangeSymbol;

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Kraite\Core\Abstracts\BaseApiableJob;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\Candle;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Support\Proxies\ApiDataMapperProxy;
use Kraite\Core\Support\Proxies\ApiRESTProxy;
use Kraite\Core\Support\ValueObjects\ApiCredentials;
use RuntimeException;

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

        // Call the exchange API
        $response = $api->getKlines($properties);

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
     * @param  array<int, array<string, mixed>>  $buffer
     */
    private function upsertWithLock(array $buffer, int $maxAttempts = 5): void
    {
        $lockKey = "candles_{$this->exchangeSymbol->id}_{$this->timeframe}";
        $lockTimeout = 30;
        $lockAcquired = false;

        try {
            $result = DB::selectOne('SELECT GET_LOCK(?, ?) as lock_result', [$lockKey, $lockTimeout]);

            if (! $result || $result->lock_result !== 1) {
                throw new RuntimeException("Failed to acquire advisory lock for {$lockKey} after {$lockTimeout}s");
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
