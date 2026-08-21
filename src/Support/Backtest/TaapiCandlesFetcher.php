<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Backtest;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Kraite\Core\Enums\BacktestTimeframe;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Support\Apis\REST\TaapiApi;
use Kraite\Core\Support\ValueObjects\ApiCredentials;
use Kraite\Core\Support\ValueObjects\ApiProperties;
use RuntimeException;
use Throwable;

/**
 * TaapiCandlesFetcher
 *
 * Fills the recency gap between Binance Vision's last completed month
 * and "now" using TAAPI's `candles` endpoint. Vision archives run
 * ~1 month behind live data, so everything after the cut-over needs
 * TAAPI to stay current for backtesting.
 *
 * This is NOT the primary history source — it's a top-up. For bulk
 * multi-month seeding use `BinanceVisionCandleFetcher`, which is free
 * and rate-unlimited.
 *
 * Strategy: issue ONE bounded call (results=caller-chosen, backtrack=0)
 * and upsert. Caller drives repeat invocations for deeper windows.
 * Authentication, API versioning, and request throttling live behind TaapiApi.
 */
final class TaapiCandlesFetcher
{
    private const MAX_RESULTS_PER_CALL = 300;

    /**
     * Fetch recent candles via TAAPI and upsert them.
     *
     * Intelligent mode: caps the HTTP call at the number of candles
     * actually missing in the DB. If the latest closed candle is already
     * present, the call is skipped entirely — TAAPI rate-limit budget is
     * shared with production ingestion, so every avoided call matters.
     *
     * @param  string  $timeframe  One of BacktestTimeframe::values().
     * @param  int  $results  Upper bound on candles per call (<=300). Actual call uses min($results, gap+buffer).
     * @param  int  $backtrack  Shift window backwards by N candles (0 = latest). Forces an HTTP call when >0.
     * @return array{inserted: int, earliest: string|null, latest: string|null, source_url: string, skipped?: bool, reason?: string, requested?: int}
     */
    public function fetch(
        ExchangeSymbol $symbol,
        string $timeframe,
        int $results = 200,
        int $backtrack = 0,
    ): array {
        $this->assertSupportedTimeframe($timeframe);
        if ($results < 1 || $results > self::MAX_RESULTS_PER_CALL) {
            throw new InvalidArgumentException(sprintf(
                'results must be 1..%d (got %d).',
                self::MAX_RESULTS_PER_CALL,
                $results
            ));
        }
        if ($backtrack < 0) {
            throw new InvalidArgumentException("backtrack must be >= 0 (got {$backtrack}).");
        }

        // Only run the DB-gap optimisation when caller wants the live tail
        // (backtrack=0). A positive backtrack is an explicit "I want history
        // at offset X" signal — respect it.
        if ($backtrack === 0) {
            ['gap' => $gap, 'latest_ts' => $latestTs] = $this->missingCandleCount($symbol, $timeframe);
            if ($gap === 0) {
                return [
                    'inserted' => 0,
                    'earliest' => null,
                    'latest' => $latestTs !== null
                        ? Carbon::createFromTimestamp($latestTs, 'UTC')->format('Y-m-d H:i:s')
                        : null,
                    'source_url' => TaapiApi::configuredBaseUrl().'/candles',
                    'skipped' => true,
                    'reason' => 'DB already holds the latest closed candle.',
                ];
            }

            // +2 candle buffer: covers the in-progress candle TAAPI may
            // include and any boundary slack between local clock and the
            // exchange's candle close.
            $results = min($results, $gap + 2);
        }

        $exchangeCanonical = $this->mapTaapiExchange($symbol);
        $taapiSymbol = mb_strtoupper($symbol->token).'/'.mb_strtoupper($symbol->quote);
        $api = new TaapiApi($this->credentials());
        $response = $api->getIndicatorValues(new ApiProperties([
            'relatable' => $symbol,
            'options' => [
                'endpoint' => 'candles',
                'exchange' => $exchangeCanonical,
                'symbol' => $taapiSymbol,
                'interval' => $timeframe,
                'results' => $results,
                'backtrack' => $backtrack,
                'addResultTimestamp' => true,
            ],
        ]));
        $data = json_decode((string) $response->getBody(), true);
        if (! is_array($data)) {
            throw new RuntimeException('TAAPI response was not JSON-parseable into an array.');
        }
        $sourceUrl = $api->baseUrl().'/candles';

        $rows = $this->normalizeRows($data, $symbol, $timeframe);
        if (empty($rows)) {
            return [
                'inserted' => 0,
                'earliest' => null,
                'latest' => null,
                'source_url' => $sourceUrl,
            ];
        }

        $earliest = null;
        $latest = null;
        foreach ($rows as $row) {
            $ts = (int) $row['timestamp'];
            $earliest = $earliest === null ? $ts : min($earliest, $ts);
            $latest = $latest === null ? $ts : max($latest, $ts);
        }

        DB::table('candles')->upsert(
            $rows,
            ['exchange_symbol_id', 'timeframe', 'timestamp'],
            ['open', 'high', 'low', 'close', 'volume', 'candle_time_utc', 'candle_time_local', 'updated_at']
        );

        return [
            'inserted' => count($rows),
            'earliest' => Carbon::createFromTimestamp($earliest, 'UTC')->format('Y-m-d H:i:s'),
            'latest' => Carbon::createFromTimestamp($latest, 'UTC')->format('Y-m-d H:i:s'),
            'source_url' => $sourceUrl,
            'requested' => $results,
        ];
    }

    /**
     * How many closed candles for (symbol, timeframe) are missing between
     * the last DB timestamp and "now"? Returns 0 when DB is current; the
     * exact deficit otherwise. Empty DB returns PHP_INT_MAX so the caller
     * defers to its own `$results` upper bound.
     *
     * The latest DB timestamp is surfaced alongside the gap so callers
     * can skip-report without issuing a second `MAX(timestamp)` query.
     *
     * @return array{gap: int, latest_ts: int|null}
     */
    private function missingCandleCount(ExchangeSymbol $symbol, string $timeframe): array
    {
        $latestTs = DB::table('candles')
            ->where('exchange_symbol_id', $symbol->id)
            ->where('timeframe', $timeframe)
            ->max('timestamp');

        if ($latestTs === null) {
            return ['gap' => PHP_INT_MAX, 'latest_ts' => null];
        }

        if (! is_numeric($latestTs)) {
            throw new RuntimeException('Stored candle timestamp is not numeric.');
        }

        $latestTsInt = (int) $latestTs;
        $intervalSeconds = BacktestTimeframe::from($timeframe)->seconds();
        $currentCandleTimestamp = intdiv(time(), $intervalSeconds) * $intervalSeconds;
        $lastClosedTimestamp = $currentCandleTimestamp - $intervalSeconds;
        $gap = intdiv(max(0, $lastClosedTimestamp - $latestTsInt), $intervalSeconds);

        return ['gap' => max(0, $gap), 'latest_ts' => $latestTsInt];
    }

    /**
     * Normalize TAAPI's two possible response shapes (list of objects or
     * columnar arrays keyed by open/high/low/close/volume/timestamp) into
     * candle rows ready for upsert.
     *
     * @param  array<mixed>  $data
     * @return list<array{exchange_symbol_id: int, timeframe: string, open: string, high: string, low: string, close: string, volume: string, timestamp: int, candle_time_utc: string, candle_time_local: string, created_at: CarbonInterface, updated_at: CarbonInterface}>
     */
    private function normalizeRows(array $data, ExchangeSymbol $symbol, string $timeframe): array
    {
        $rows = [];
        $now = now();
        $intervalSeconds = BacktestTimeframe::from($timeframe)->seconds();
        $currentCandleTimestamp = intdiv(time(), $intervalSeconds) * $intervalSeconds;

        // Shape 1: array of objects [{timestamp, open, high, low, close, volume}, ...]
        if (isset($data[0]) && is_array($data[0])) {
            foreach ($data as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $tsSec = $this->coerceTimestampSeconds($row['timestamp'] ?? $row['timestampHuman'] ?? null);
                if ($tsSec === null || $tsSec >= $currentCandleTimestamp) {
                    continue;
                }
                $rows[] = $this->buildRow($symbol, $timeframe, $tsSec, $row, $now);
            }

            return $rows;
        }

        // Shape 2: columnar — {timestamp: [...], open: [...], high: [...], ...}
        if (isset($data['timestamp']) && is_array($data['timestamp'])) {
            $timestamps = $data['timestamp'];
            $open = isset($data['open']) && is_array($data['open']) ? $data['open'] : [];
            $high = isset($data['high']) && is_array($data['high']) ? $data['high'] : [];
            $low = isset($data['low']) && is_array($data['low']) ? $data['low'] : [];
            $close = isset($data['close']) && is_array($data['close']) ? $data['close'] : [];
            $volume = isset($data['volume']) && is_array($data['volume']) ? $data['volume'] : [];
            $count = count($timestamps);
            for ($i = 0; $i < $count; $i++) {
                $tsSec = $this->coerceTimestampSeconds($timestamps[$i] ?? null);
                if ($tsSec === null || $tsSec >= $currentCandleTimestamp) {
                    continue;
                }
                $rows[] = $this->buildRow($symbol, $timeframe, $tsSec, [
                    'open' => $open[$i] ?? null,
                    'high' => $high[$i] ?? null,
                    'low' => $low[$i] ?? null,
                    'close' => $close[$i] ?? null,
                    'volume' => $volume[$i] ?? 0,
                ], $now);
            }
        }

        return $rows;
    }

    /**
     * TAAPI returns timestamps either as UNIX seconds (older) or UNIX
     * milliseconds (newer). Normalise to SECONDS — matches the project
     * convention used by FetchKlinesJob and the `candles.timestamp`
     * column's existing data.
     */
    private function coerceTimestampSeconds(mixed $raw): ?int
    {
        if ($raw === null || ! is_numeric($raw)) {
            return null;
        }

        $n = (int) $raw;

        if ($n >= 1_000_000_000_000_000) {   // microseconds
            return intdiv($n, 1_000_000);
        }
        if ($n >= 1_000_000_000_000) {       // milliseconds
            return intdiv($n, 1000);
        }

        return $n;                            // already seconds
    }

    /**
     * @param  array<mixed, mixed>  $row
     * @return array{exchange_symbol_id: int, timeframe: string, open: string, high: string, low: string, close: string, volume: string, timestamp: int, candle_time_utc: string, candle_time_local: string, created_at: CarbonInterface, updated_at: CarbonInterface}
     */
    private function buildRow(ExchangeSymbol $symbol, string $timeframe, int $tsSec, array $row, CarbonInterface $now): array
    {
        $candleTime = Carbon::createFromTimestamp($tsSec, 'UTC');
        $timeZone = config('app.timezone', 'UTC');
        if (! is_string($timeZone) && ! is_int($timeZone) && ! $timeZone instanceof DateTimeZone) {
            $timeZone = 'UTC';
        }

        return [
            'exchange_symbol_id' => $symbol->id,
            'timeframe' => $timeframe,
            'open' => $this->scalarString($row['open'] ?? null, '0'),
            'high' => $this->scalarString($row['high'] ?? null, '0'),
            'low' => $this->scalarString($row['low'] ?? null, '0'),
            'close' => $this->scalarString($row['close'] ?? null, '0'),
            'volume' => $this->scalarString($row['volume'] ?? null, '0'),
            'timestamp' => $tsSec,
            'candle_time_utc' => $candleTime->format('Y-m-d H:i:s'),
            'candle_time_local' => $candleTime->copy()->setTimezone($timeZone)->format('Y-m-d H:i:s'),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function scalarString(mixed $value, string $default): string
    {
        return is_string($value) || is_int($value) || is_float($value)
            ? (string) $value
            : $default;
    }

    private function credentials(): ApiCredentials
    {
        $legacySecret = null;

        try {
            $legacySecret = Kraite::find(1)?->taapi_secret;
        } catch (Throwable) {
        }

        if (! is_string($legacySecret) || $legacySecret === '') {
            $legacySecret = config('kraite.api.credentials.taapi.secret');
        }

        return ApiCredentials::make([
            'taapi_secret' => $legacySecret,
            'taapi_v2_token' => config('kraite.api.credentials.taapi.v2_token'),
        ]);
    }

    /**
     * Map Kraite api_system canonical to TAAPI's exchange identifier.
     * TAAPI uses `binancefutures` for USDM perps, not `binance`.
     */
    private function mapTaapiExchange(ExchangeSymbol $symbol): string
    {
        $canonical = $symbol->apiSystem->canonical ?? 'binance';

        return match ($canonical) {
            'binance' => 'binancefutures',
            'bybit' => 'bybit',
            'kucoin' => 'kucoinfutures',
            'bitget' => 'bitget',
            default => 'binancefutures',
        };
    }

    private function assertSupportedTimeframe(string $timeframe): void
    {
        if (BacktestTimeframe::tryFrom($timeframe) === null) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported timeframe "%s". Allowed: %s.',
                $timeframe,
                implode(', ', BacktestTimeframe::values())
            ));
        }
    }
}
