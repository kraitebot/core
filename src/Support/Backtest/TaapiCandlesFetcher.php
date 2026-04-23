<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Backtest;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Kraite\Core\Models\ExchangeSymbol;
use RuntimeException;

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
 * TAAPI endpoint shape:
 *   GET https://api.taapi.io/candles
 *     ?secret=<KEY>&exchange=binancefutures&symbol=BTC/USDT
 *     &interval=1h&results=300&backtrack=0
 *
 * Params:
 *   results   — how many candles to return in a single response (<=300)
 *   backtrack — how many candles to shift the window back (0 = latest)
 *
 * Strategy: issue ONE bounded call (results=caller-chosen, backtrack=0)
 * and upsert. Caller drives repeat invocations for deeper windows.
 * Keeps this class leaf-simple; the throttler / orchestration question
 * lives at the queue layer if we ever run this at scale.
 */
final class TaapiCandlesFetcher
{
    private const BASE_URL = 'https://api.taapi.io/candles';

    private const SUPPORTED_TIMEFRAMES = ['1h', '4h', '12h'];

    private const MAX_RESULTS_PER_CALL = 300;

    /**
     * Fetch recent candles via TAAPI and upsert them.
     *
     * @param  string  $timeframe  One of SUPPORTED_TIMEFRAMES.
     * @param  int  $results  How many candles to return in one call (<=300).
     * @param  int  $backtrack  Shift window backwards by N candles (0 = latest).
     * @return array{inserted: int, earliest: string|null, latest: string|null, source_url: string}
     */
    public function fetch(ExchangeSymbol $symbol, string $timeframe, int $results = 200, int $backtrack = 0): array
    {
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

        $secret = config('services.taapi.secret') ?? env('TAAPI_SECRET');
        if (! $secret) {
            throw new RuntimeException('TAAPI_SECRET is not configured.');
        }

        $exchangeCanonical = $this->mapTaapiExchange($symbol);
        $taapiSymbol = mb_strtoupper($symbol->token).'/'.mb_strtoupper($symbol->quote);

        $params = [
            'secret' => $secret,
            'exchange' => $exchangeCanonical,
            'symbol' => $taapiSymbol,
            'interval' => $timeframe,
            'results' => $results,
            'backtrack' => $backtrack,
            'addResultTimestamp' => 'true',
        ];

        $response = Http::timeout(60)->get(self::BASE_URL, $params);

        if (! $response->successful()) {
            throw new RuntimeException(sprintf(
                'TAAPI candles call failed — HTTP %d — body: %s',
                $response->status(),
                mb_substr($response->body(), 0, 200)
            ));
        }

        $data = $response->json();
        if (! is_array($data)) {
            throw new RuntimeException('TAAPI response was not JSON-parseable into an array.');
        }

        $rows = $this->normalizeRows($data, $symbol, $timeframe);
        if (empty($rows)) {
            return [
                'inserted' => 0,
                'earliest' => null,
                'latest' => null,
                'source_url' => self::BASE_URL,
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
            'source_url' => self::BASE_URL,
        ];
    }

    /**
     * Normalize TAAPI's two possible response shapes (list of objects or
     * columnar arrays keyed by open/high/low/close/volume/timestamp) into
     * candle rows ready for upsert.
     *
     * @param  array<mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    private function normalizeRows(array $data, ExchangeSymbol $symbol, string $timeframe): array
    {
        $rows = [];
        $now = now();

        // Shape 1: array of objects [{timestamp, open, high, low, close, volume}, ...]
        if (isset($data[0]) && is_array($data[0])) {
            foreach ($data as $row) {
                $tsSec = $this->coerceTimestampSeconds($row['timestamp'] ?? $row['timestampHuman'] ?? null);
                if ($tsSec === null) {
                    continue;
                }
                $rows[] = $this->buildRow($symbol, $timeframe, $tsSec, $row, $now);
            }

            return $rows;
        }

        // Shape 2: columnar — {timestamp: [...], open: [...], high: [...], ...}
        if (isset($data['timestamp']) && is_array($data['timestamp'])) {
            $count = count($data['timestamp']);
            for ($i = 0; $i < $count; $i++) {
                $tsSec = $this->coerceTimestampSeconds($data['timestamp'][$i] ?? null);
                if ($tsSec === null) {
                    continue;
                }
                $rows[] = $this->buildRow($symbol, $timeframe, $tsSec, [
                    'open' => $data['open'][$i] ?? null,
                    'high' => $data['high'][$i] ?? null,
                    'low' => $data['low'][$i] ?? null,
                    'close' => $data['close'][$i] ?? null,
                    'volume' => $data['volume'][$i] ?? 0,
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
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function buildRow(ExchangeSymbol $symbol, string $timeframe, int $tsSec, array $row, mixed $now): array
    {
        $candleTime = Carbon::createFromTimestamp($tsSec, 'UTC');

        return [
            'exchange_symbol_id' => $symbol->id,
            'timeframe' => $timeframe,
            'open' => (string) ($row['open'] ?? '0'),
            'high' => (string) ($row['high'] ?? '0'),
            'low' => (string) ($row['low'] ?? '0'),
            'close' => (string) ($row['close'] ?? '0'),
            'volume' => (string) ($row['volume'] ?? '0'),
            'timestamp' => $tsSec,
            'candle_time_utc' => $candleTime->format('Y-m-d H:i:s'),
            'candle_time_local' => $candleTime->copy()->setTimezone(config('app.timezone', 'UTC'))->format('Y-m-d H:i:s'),
            'created_at' => $now,
            'updated_at' => $now,
        ];
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
        if (! in_array($timeframe, self::SUPPORTED_TIMEFRAMES, true)) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported timeframe "%s". Allowed: %s.',
                $timeframe,
                implode(', ', self::SUPPORTED_TIMEFRAMES)
            ));
        }
    }
}
