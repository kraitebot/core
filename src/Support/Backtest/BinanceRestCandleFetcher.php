<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Backtest;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Kraite\Core\Enums\BacktestTimeframe;
use Kraite\Core\Models\ExchangeSymbol;
use RuntimeException;

/**
 * BinanceRestCandleFetcher
 *
 * Fills the recency gap that `BinanceVisionCandleFetcher` leaves open.
 *
 * Vision only serves completed months — as of 2026-04-24 the latest
 * Vision archive is `2026-03-*`. Everything from `2026-04-01` onward
 * has to come from somewhere else. Production ingestion uses the
 * exchange's own public REST endpoint (`FetchKlinesJob` via
 * `ApiDataMapperProxy`); this fetcher is the backtesting-side
 * equivalent — direct HTTP, no auth required, single-shot or chunked
 * depending on gap size.
 *
 * Binance USDM futures klines:
 *   GET https://fapi.binance.com/fapi/v1/klines
 *     ?symbol=BTCUSDT&interval=1h&startTime=<ms>&limit=1500
 *
 * Strategy:
 * - Start at (latestDbTs + interval) or the explicit `$sinceTs` arg.
 * - Page forward in 1500-candle batches until we reach "now" or the
 *   exchange returns fewer than the requested limit (= tail reached).
 * - Upsert keyed by (exchange_symbol_id, timeframe, timestamp) — same
 *   index Vision and TAAPI use, so re-running is idempotent.
 * - Binance-only by design. Other exchanges keep using TAAPI (their
 *   Vision equivalents aren't supported).
 */
final class BinanceRestCandleFetcher
{
    private const BASE_URL = 'https://fapi.binance.com/fapi/v1/klines';

    private const MAX_CANDLES_PER_CALL = 1500;

    /**
     * Fetch klines from Binance REST and upsert. If `$sinceTs` is null
     * the fetcher computes it from the latest candle in the DB + one
     * interval — so the caller typically just provides (symbol, timeframe).
     *
     * @param  int|null  $sinceTs  Unix seconds lower bound (inclusive). Null = resume from DB.
     * @return array{
     *   inserted: int,
     *   earliest: string|null,
     *   latest: string|null,
     *   pages: int,
     *   skipped?: bool,
     *   reason?: string
     * }
     */
    public function fetch(ExchangeSymbol $symbol, string $timeframe, ?int $sinceTs = null): array
    {
        $this->assertBinanceSymbol($symbol);
        $this->assertSupportedTimeframe($timeframe);

        $intervalSec = BacktestTimeframe::from($timeframe)->seconds();
        $resumeTs = $sinceTs ?? $this->resumeFromDb($symbol, $timeframe, $intervalSec);
        $currentCandleTs = intdiv(time(), $intervalSec) * $intervalSec;

        if ($resumeTs >= $currentCandleTs) {
            return [
                'inserted' => 0,
                'earliest' => null,
                'latest' => null,
                'pages' => 0,
                'skipped' => true,
                'reason' => 'DB already holds the latest closed candle.',
            ];
        }

        $binanceSymbol = $this->binanceApiSymbol($symbol);
        $inserted = 0;
        $earliest = null;
        $latest = null;
        $pages = 0;
        $cursor = $resumeTs;

        // Hard safety cap — a pathological loop over 2y of 1h candles would
        // be ~12 pages; anything beyond 50 means a bug in the termination logic.
        while ($cursor < $currentCandleTs && $pages < 50) {
            $batch = $this->fetchPage($binanceSymbol, $timeframe, $cursor);
            $pages++;

            if (empty($batch)) {
                break;
            }

            $rows = $this->normalizeRows($batch, $symbol, $timeframe);
            if (empty($rows)) {
                break;
            }

            $pageInserted = $this->upsert($rows);
            $inserted += $pageInserted;

            $firstTs = (int) $rows[0]['timestamp'];
            $lastTs = (int) $rows[count($rows) - 1]['timestamp'];
            $earliest = $earliest === null ? $firstTs : min($earliest, $firstTs);
            $latest = $latest === null ? $lastTs : max($latest, $lastTs);

            // Continue past the last candle returned; if the batch came
            // back shorter than the requested limit, we've reached the tail.
            if (count($batch) < self::MAX_CANDLES_PER_CALL) {
                break;
            }

            $cursor = $lastTs + $intervalSec;
        }

        return [
            'inserted' => $inserted,
            'earliest' => $earliest !== null
                ? Carbon::createFromTimestamp($earliest, 'UTC')->format('Y-m-d H:i:s')
                : null,
            'latest' => $latest !== null
                ? Carbon::createFromTimestamp($latest, 'UTC')->format('Y-m-d H:i:s')
                : null,
            'pages' => $pages,
        ];
    }

    /**
     * Scan the DB for contiguous missing intervals in [lookbackTs, now] and
     * fetch each gap directly from Binance REST. Complements fetch(), which
     * only resumes forward from the latest DB row — if the DB has a hole
     * BEFORE the latest row (common when a timeframe was enabled after
     * historical data was already partially present), the forward-resume
     * can't fill it. Gap-scan closes that.
     *
     * @param  int|null  $lookbackTs  Oldest epoch to check for gaps. Null = 6 months back.
     * @return array{
     *   gaps_found: int,
     *   gaps_filled: int,
     *   inserted: int,
     *   skipped: array<int, array{from: string, to: string, missing: int, reason: string}>
     * }
     */
    public function fillGaps(ExchangeSymbol $symbol, string $timeframe, ?int $lookbackTs = null): array
    {
        $this->assertBinanceSymbol($symbol);
        $this->assertSupportedTimeframe($timeframe);

        $intervalSec = BacktestTimeframe::from($timeframe)->seconds();
        $requestedStartTs = $lookbackTs ?? (time() - 180 * 86400);
        $startTs = intdiv($requestedStartTs + $intervalSec - 1, $intervalSec) * $intervalSec;
        $endTs = (int) (intdiv(time(), $intervalSec) * $intervalSec);

        $existing = DB::table('candles')
            ->where('exchange_symbol_id', $symbol->id)
            ->where('timeframe', $timeframe)
            ->whereBetween('timestamp', [$startTs, $endTs])
            ->pluck('timestamp')
            ->map(fn ($t) => (int) $t)
            ->flip();

        // Walk the expected interval grid; batch contiguous missing runs.
        $gaps = [];
        $gapStart = null;
        for ($ts = $startTs; $ts < $endTs; $ts += $intervalSec) {
            if (! $existing->has($ts)) {
                $gapStart ??= $ts;
            } elseif ($gapStart !== null) {
                $gaps[] = [$gapStart, $ts - $intervalSec];
                $gapStart = null;
            }
        }
        if ($gapStart !== null) {
            $gaps[] = [$gapStart, $endTs - $intervalSec];
        }

        $inserted = 0;
        $filled = 0;
        $skipped = [];
        $binanceSymbol = $this->binanceApiSymbol($symbol);

        foreach ($gaps as [$gStart, $gEnd]) {
            $cursor = $gStart;
            $gapInserted = 0;
            $safety = 0;

            while ($cursor <= $gEnd && $safety < 10) {
                $batch = $this->fetchPage($binanceSymbol, $timeframe, $cursor);
                $safety++;

                if (empty($batch)) {
                    break;
                }

                $rows = $this->normalizeRows($batch, $symbol, $timeframe);
                if (empty($rows)) {
                    break;
                }

                $pageInserted = $this->upsert($rows);
                $inserted += $pageInserted;
                $gapInserted += $pageInserted;

                $lastTs = (int) $rows[count($rows) - 1]['timestamp'];
                if ($lastTs >= $gEnd || count($batch) < self::MAX_CANDLES_PER_CALL) {
                    break;
                }
                $cursor = $lastTs + $intervalSec;
            }

            if ($gapInserted > 0) {
                $filled++;
            } else {
                $expectedMissing = intdiv($gEnd - $gStart, $intervalSec) + 1;
                $skipped[] = [
                    'from' => Carbon::createFromTimestamp($gStart, 'UTC')->format('Y-m-d H:i:s'),
                    'to' => Carbon::createFromTimestamp($gEnd, 'UTC')->format('Y-m-d H:i:s'),
                    'missing' => $expectedMissing,
                    'reason' => 'Binance returned no data for this range (symbol may not have been listed yet).',
                ];
            }
        }

        return [
            'gaps_found' => count($gaps),
            'gaps_filled' => $filled,
            'inserted' => $inserted,
            'skipped' => $skipped,
        ];
    }

    /**
     * Issue a single klines request. Binance returns an array of arrays:
     *   [openTime, open, high, low, close, volume, closeTime, ...]
     *
     * @return array<int, array<int, mixed>>
     */
    private function fetchPage(string $binanceSymbol, string $timeframe, int $startTs): array
    {
        $response = Http::timeout(30)->get(self::BASE_URL, [
            'symbol' => $binanceSymbol,
            'interval' => $timeframe,
            'startTime' => $startTs * 1000,
            'limit' => self::MAX_CANDLES_PER_CALL,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException(sprintf(
                'Binance REST klines HTTP %d — body: %s',
                $response->status(),
                mb_substr($response->body(), 0, 200)
            ));
        }

        $data = $response->json();
        if (! is_array($data)) {
            throw new RuntimeException('Binance REST response was not JSON-parseable into an array.');
        }

        return $data;
    }

    /**
     * Turn raw kline arrays into rows ready for upsert. Binance openTime
     * is milliseconds — normalise to seconds to match the project's
     * candle table convention used by FetchKlinesJob and Vision.
     *
     * @param  array<int, array<int, mixed>>  $batch
     * @return array<int, array<string, mixed>>
     */
    private function normalizeRows(array $batch, ExchangeSymbol $symbol, string $timeframe): array
    {
        $now = now();
        $tz = config('app.timezone', 'UTC');
        $rows = [];
        $intervalSeconds = BacktestTimeframe::from($timeframe)->seconds();
        $currentCandleTimestamp = intdiv(time(), $intervalSeconds) * $intervalSeconds;

        foreach ($batch as $kline) {
            if (! is_array($kline) || count($kline) < 6) {
                continue;
            }

            $ts = intdiv((int) $kline[0], 1000);
            if ($ts >= $currentCandleTimestamp) {
                continue;
            }

            $time = Carbon::createFromTimestamp($ts, 'UTC');

            $rows[] = [
                'exchange_symbol_id' => $symbol->id,
                'timeframe' => $timeframe,
                'timestamp' => $ts,
                'candle_time_utc' => $time->format('Y-m-d H:i:s'),
                'candle_time_local' => $time->copy()->setTimezone($tz)->format('Y-m-d H:i:s'),
                'open' => (string) $kline[1],
                'high' => (string) $kline[2],
                'low' => (string) $kline[3],
                'close' => (string) $kline[4],
                'volume' => (string) $kline[5],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function upsert(array $rows): int
    {
        DB::table('candles')->upsert(
            $rows,
            ['exchange_symbol_id', 'timeframe', 'timestamp'],
            ['open', 'high', 'low', 'close', 'volume', 'candle_time_utc', 'candle_time_local', 'updated_at']
        );

        return count($rows);
    }

    private function resumeFromDb(ExchangeSymbol $symbol, string $timeframe, int $intervalSec): int
    {
        $latest = DB::table('candles')
            ->where('exchange_symbol_id', $symbol->id)
            ->where('timeframe', $timeframe)
            ->max('timestamp');

        if ($latest === null) {
            // Empty table — start from ~30 days ago. Vision is expected to
            // have handled deeper history; this fetcher only covers the tail.
            return time() - (30 * 86400);
        }

        return (int) $latest + $intervalSec;
    }

    private function binanceApiSymbol(ExchangeSymbol $symbol): string
    {
        return mb_strtoupper($symbol->token.$symbol->quote);
    }

    private function assertBinanceSymbol(ExchangeSymbol $symbol): void
    {
        $canonical = $symbol->apiSystem->canonical ?? null;
        if ($canonical !== 'binance') {
            throw new InvalidArgumentException(sprintf(
                'BinanceRestCandleFetcher only supports Binance symbols — got "%s".',
                $canonical ?? 'unknown'
            ));
        }
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
