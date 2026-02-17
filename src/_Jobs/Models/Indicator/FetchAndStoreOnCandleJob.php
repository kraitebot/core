<?php

declare(strict_types=1);

namespace Kraite\Core\_Jobs\Models\Indicator;

use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Kraite\Core\Abstracts\BaseApiableJob;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\Candle;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Indicator;
use RuntimeException;
use Throwable;

use function count;

final class FetchAndStoreOnCandleJob extends BaseApiableJob
{
    public ExchangeSymbol $exchangeSymbol;

    /** e.g. 'candles' */
    public string $canonical;

    /** '1h' | '2h' | '4h' | '1d' */
    public string $timeframe;

    public array $params;

    public function __construct(
        int $exchangeSymbolId,
        string $canonical,
        string $timeframe,
        array $params = []
    ) {
        $this->exchangeSymbol = ExchangeSymbol::findOrFail($exchangeSymbolId);
        $this->canonical = $canonical;
        $this->timeframe = $timeframe;
        $this->params = $params;
        $this->retries = 300;
    }

    public function assignExceptionHandler(): void
    {
        $this->exceptionHandler = BaseExceptionHandler::make('taapi')
            ->withAccount(Account::admin('taapi'));
    }

    public function relatable()
    {
        return $this->exchangeSymbol;
    }

    public function computeApiable()
    {
        // Resolve indicator implementation
        $indicatorModel = Indicator::query()
            ->where('canonical', $this->canonical)
            ->where('is_active', 1)
            ->firstOrFail();

        $class = $indicatorModel->class;

        // Merge default params (interval) with caller overrides
        $defaultParams = ['interval' => $this->timeframe];
        $mergedParams = array_replace($defaultParams, $this->params);

        /** @var \Kraite\Core\Abstracts\BaseIndicator $indicator */
        $indicator = new $class($this->exchangeSymbol, $mergedParams);

        // Significant jitter to prevent concurrent upsert deadlocks across workers
        Sleep::for(random_int(2_000, 10_000))->milliseconds();

        // Fetch raw candles from provider (Taapi)
        $data = $indicator->compute();

        // Normalize into rows with consistent keys
        $rows = $this->normalizeToRows($data);
        if (empty($rows)) {
            return ['stored' => true, 'upserts' => 0];
        }

        $now = now();
        $count = 0;
        $chunkSize = 50; // Reduced from 1000 to minimize deadlock window
        $buffer = [];

        foreach ($rows as $row) {
            // Require OHLC + timestamp
            if (! isset($row['timestamp'], $row['open'], $row['high'], $row['low'], $row['close'])) {
                continue;
            }

            // Normalize epoch (accept seconds or milliseconds)
            $epochSec = $this->normalizeEpochToSeconds($row['timestamp']);

            // Convert to a real SQL datetime in UTC (your new column)
            $candleTime = Carbon::createFromTimestampUTC($epochSec)->format('Y-m-d H:i:s');

            // Buffer row for upsert
            $buffer[] = [
                'exchange_symbol_id' => $this->exchangeSymbol->id,
                'timeframe' => $this->timeframe,
                'timestamp' => $epochSec,                 // keep raw epoch (seconds)
                'candle_time' => $candleTime,               // new searchable datetime
                'open' => (string) $row['open'],
                'high' => (string) $row['high'],
                'low' => (string) $row['low'],
                'close' => (string) $row['close'],
                'volume' => (string) ($row['volume'] ?? '0'),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            // Flush in chunks for memory/perf
            if (count($buffer) >= $chunkSize) {
                $this->upsertWithDeadlockRetry($buffer);
                $count += count($buffer);
                $buffer = [];
            }
        }

        // Flush tail
        if (! empty($buffer)) {
            $this->upsertWithDeadlockRetry($buffer);
            $count += count($buffer);
        }

        return ['stored' => true, 'upserts' => $count];
    }

    public function ignoreException(Throwable $e)
    {
        $ignoredMessages = [
            'An unknown error occurred. Please check your parameters',
            'Connection reset by peer',
        ];

        if ($e instanceof RequestException && $e->hasResponse()) {
            $body = (string) $e->getResponse()->getBody();
            foreach ($ignoredMessages as $msg) {
                if (!(str_contains(haystack: $body, needle: $msg))) {
                    continue;
                }

                return true;
            }
        }

        foreach ($ignoredMessages as $msg) {
            if (!(str_contains(haystack: $e->getMessage(), needle: $msg))) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * Normalize provider output into a list of rows with canonical keys.
     *
     * Accepts:
     *  - list of associative arrays with keys {timestamp, open, high, low, close, volume?}
     *  - columnar arrays: ['timestamp'=>[], 'open'=>[], 'high'=>[], 'low'=>[], 'close'=>[], 'volume'?=>[]]
     *  - single associative array candle
     */
    public function normalizeToRows($data): array
    {
        // Array of candle objects
        if (is_array($data) && array_is_list($data) && isset($data[0]) && is_array($data[0])) {
            return $data;
        }

        // Columnar arrays
        if (is_array($data) &&
            isset($data['timestamp'], $data['open'], $data['high'], $data['low'], $data['close']) &&
            is_array($data['timestamp']) && is_array($data['open']) && is_array($data['high']) &&
            is_array($data['low']) && is_array($data['close'])
        ) {
            $ts = $data['timestamp'];
            $open = $data['open'];
            $high = $data['high'];
            $low = $data['low'];
            $close = $data['close'];
            $volume = isset($data['volume']) && is_array($data['volume']) ? $data['volume'] : null;

            $len = min(
                count($ts),
                count($open),
                count($high),
                count($low),
                count($close),
                $volume ? count($volume) : PHP_INT_MAX
            );

            $rows = [];
            for ($i = 0; $i < $len; $i++) {
                $rows[] = [
                    'timestamp' => (int) $ts[$i],
                    'open' => $open[$i],
                    'high' => $high[$i],
                    'low' => $low[$i],
                    'close' => $close[$i],
                    'volume' => $volume ? $volume[$i] : null,
                ];
            }

            return $rows;
        }

        // Single candle object
        if (is_array($data) && isset($data['timestamp'], $data['open'], $data['high'], $data['low'], $data['close'])) {
            return [$data];
        }

        return [];
    }

    /**
     * Decide if an incoming epoch is in seconds or milliseconds and normalize to seconds.
     *
     * - If >= 10^12, assume milliseconds.
     * - Otherwise, assume seconds.
     */
    public function normalizeEpochToSeconds($epoch): int
    {
        // Ensure numeric
        $val = (int) $epoch;

        // Heuristic: >= 1_000_000_000_000 (approx year 2001 in ms) => ms
        if ($val >= 1_000_000_000_000) {
            return intdiv($val, 1000);
        }

        return $val;
    }

    /**
     * Upsert candles with advisory lock to prevent deadlocks.
     *
     * Uses MySQL GET_LOCK to serialize upserts per symbol/timeframe.
     * This prevents deadlocks by ensuring only one worker can upsert
     * candles for a given symbol+timeframe at a time.
     *
     * Different symbols can still run in parallel across workers.
     *
     * COMPATIBILITY WITH BaseQueueableJob RETRY:
     * - Lock timeout exceptions → caught by global DatabaseExceptionHandler → job retries automatically
     * - Deadlock exceptions → local retry (5 attempts), then global handler → job retries automatically
     * - Lock is ALWAYS released in finally block (even on exception)
     * - MySQL auto-releases lock if connection dies (process crash safety)
     */
    public function upsertWithDeadlockRetry(array $buffer, int $maxAttempts = 5): void
    {
        // Advisory lock key: candles_{symbol_id}_{timeframe}
        // This serializes upserts for same symbol+timeframe, but allows
        // parallel upserts for different symbols
        $lockKey = "candles_{$this->exchangeSymbol->id}_{$this->timeframe}";
        $lockTimeout = 30; // seconds
        $lockAcquired = false;

        try {
            // Acquire advisory lock (waits up to 30s)
            $result = DB::selectOne('SELECT GET_LOCK(?, ?) as lock_result', [$lockKey, $lockTimeout]);

            if (! $result || $result->lock_result !== 1) {
                // Lock acquisition failed - will be caught by global DatabaseExceptionHandler
                // which retries the entire job via BaseQueueableJob retry mechanism
                throw new RuntimeException("Failed to acquire advisory lock for {$lockKey} after {$lockTimeout}s. Another worker may be processing same symbol+timeframe.");
            }

            $lockAcquired = true;

            // Upsert with retry as fallback (in case of other transient errors)
            $attempt = 0;

            while ($attempt < $maxAttempts) {
                try {
                    DB::transaction(static function () use ($buffer) {
                        Candle::query()->upsert(
                            $buffer,
                            // Unique key: avoid duplicates per (symbol, timeframe, timestamp)
                            ['exchange_symbol_id', 'timeframe', 'timestamp'],
                            // On conflict, update latest values including candle_time
                            ['open', 'high', 'low', 'close', 'volume', 'candle_time', 'updated_at']
                        );
                    });

                    // Success - exit retry loop
                    return;
                } catch (\Illuminate\Database\QueryException $e) {
                    // With advisory locks, deadlocks should be extremely rare
                    // But keep local retry as optimization (avoids full job dispatch cycle)
                    if ($e->getCode() === '40001' || str_contains(haystack: $e->getMessage(), needle: 'Deadlock')) {
                        $attempt++;

                        if ($attempt >= $maxAttempts) {
                            // Exhausted local retries - re-throw to trigger global DatabaseExceptionHandler
                            // which will retry the entire job (including lock acquisition) via BaseQueueableJob
                            throw $e;
                        }

                        // Exponential backoff: 100ms, 200ms, 400ms, 800ms, 1600ms
                        $backoffMs = 100 * (2 ** ($attempt - 1));
                        Sleep::for($backoffMs)->milliseconds();

                        continue;
                    }

                    // Not a deadlock - re-throw immediately
                    throw $e;
                }
            }
        } finally {
            // Always release the lock, even if upsert fails
            // Only release if we actually acquired it
            if ($lockAcquired) {
                DB::selectOne('SELECT RELEASE_LOCK(?) as release_result', [$lockKey]);
            }
        }
    }
}
