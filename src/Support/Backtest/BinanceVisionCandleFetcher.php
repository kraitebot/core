<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Backtest;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Kraite\Core\Models\ExchangeSymbol;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * BinanceVisionCandleFetcher
 *
 * Bulk-downloads historical USDM perpetual futures klines from
 * `data.binance.vision`, the official Binance archive. Used to seed
 * the `candles` table for backtesting — orders of magnitude faster
 * than the TAAPI `candles` endpoint (no per-call rate limit, one HTTP
 * request per month, free).
 *
 * URL shape for each monthly ZIP:
 *   https://data.binance.vision/data/futures/um/monthly/klines/
 *   {SYMBOL}/{INTERVAL}/{SYMBOL}-{INTERVAL}-{YYYY}-{MM}.zip
 *
 * Each ZIP contains a single CSV with columns:
 *   open_time, open, high, low, close, volume, close_time, quote_volume,
 *   count, taker_buy_volume, taker_buy_quote_volume, ignore
 *
 * Strategy:
 * - Walk backwards month by month from (lastCompleteMonth) down to the
 *   per-symbol cap (default 24 months).
 * - 404 on a month = symbol wasn't listed that far back OR still being
 *   archived (most recent month often appears a few hours late). Stop
 *   walking once we hit the first 404 BELOW any successful month.
 * - Upsert into `candles` on conflict (unique by exchange_symbol_id +
 *   timeframe + timestamp), so re-running the fetcher is idempotent.
 * - Only operates on Binance-native ExchangeSymbols (other exchanges
 *   have their own archives; not in scope here).
 */
final class BinanceVisionCandleFetcher
{
    private const VISION_BASE_URL = 'https://data.binance.vision/data/futures/um/monthly/klines';

    private const SUPPORTED_TIMEFRAMES = ['1h', '4h', '12h', '1d'];

    private const DEFAULT_MAX_MONTHS = 24;

    /**
     * Fetch monthly ZIPs for a symbol + timeframe and upsert candles.
     *
     * @param  ExchangeSymbol  $symbol  Must be a Binance exchange_symbol.
     * @param  string  $timeframe  One of SUPPORTED_TIMEFRAMES.
     * @param  int  $maxMonths  Hard cap on months-back (default 24).
     * @return array{
     *   months_downloaded: int,
     *   months_skipped_404: int,
     *   months_already_covered: int,
     *   candles_upserted: int,
     *   earliest_candle: string|null,
     *   latest_candle: string|null,
     *   errors: array<int, string>
     * }
     */
    public function fetch(ExchangeSymbol $symbol, string $timeframe, int $maxMonths = self::DEFAULT_MAX_MONTHS): array
    {
        $this->assertBinanceSymbol($symbol);
        $this->assertSupportedTimeframe($timeframe);
        if ($maxMonths < 1 || $maxMonths > 48) {
            throw new InvalidArgumentException("maxMonths must be between 1 and 48 (got {$maxMonths}).");
        }

        $binanceSymbol = $this->binanceApiSymbol($symbol);
        $cursor = Carbon::now('UTC')->startOfMonth()->subMonth();
        $oldestAllowed = Carbon::now('UTC')->startOfMonth()->subMonths($maxMonths);

        $report = [
            'months_downloaded' => 0,
            'months_skipped_404' => 0,
            'months_already_covered' => 0,
            'candles_upserted' => 0,
            'earliest_candle' => null,
            'latest_candle' => null,
            'errors' => [],
        ];

        $anySuccessYet = false;
        $consecutive404s = 0;

        while ($cursor->greaterThanOrEqualTo($oldestAllowed)) {
            // Skip download entirely when DB already holds every candle for
            // this month. One cheap COUNT beats re-downloading a 1–5 MB ZIP
            // and re-upserting 700+ rows.
            if ($this->isMonthFullyCovered($symbol, $timeframe, $cursor)) {
                $report['months_already_covered']++;
                $anySuccessYet = true;
                $consecutive404s = 0;
                $cursor->subMonth();

                continue;
            }

            $url = $this->monthlyUrl($binanceSymbol, $timeframe, $cursor);

            try {
                $outcome = $this->downloadAndStore($url, $symbol, $timeframe);
            } catch (Throwable $e) {
                $report['errors'][] = sprintf('[%s] %s', $cursor->format('Y-m'), $e->getMessage());
                $cursor->subMonth();

                continue;
            }

            if ($outcome === null) {
                // 404 — either symbol not yet listed, or month not
                // archived yet. Track to know when to give up.
                $report['months_skipped_404']++;
                $consecutive404s++;

                // Only bail on sustained 404s AFTER we've already
                // successfully downloaded at least one month — prevents
                // falsely deciding a symbol doesn't exist just because
                // the current month isn't archived yet.
                if ($anySuccessYet && $consecutive404s >= 2) {
                    break;
                }
                $cursor->subMonth();

                continue;
            }

            $anySuccessYet = true;
            $consecutive404s = 0;
            $report['months_downloaded']++;
            $report['candles_upserted'] += $outcome['inserted'];

            if ($outcome['earliest'] !== null) {
                $report['earliest_candle'] = $report['earliest_candle'] === null
                    ? $outcome['earliest']
                    : (min($report['earliest_candle'], $outcome['earliest']));
            }
            if ($outcome['latest'] !== null) {
                $report['latest_candle'] = $report['latest_candle'] === null
                    ? $outcome['latest']
                    : max($report['latest_candle'], $outcome['latest']);
            }

            $cursor->subMonth();
        }

        return $report;
    }

    /**
     * Download a single monthly ZIP, parse its CSV, and upsert rows.
     * Returns null on 404 so caller can drive the outer loop.
     *
     * @return array{inserted: int, earliest: string|null, latest: string|null}|null
     */
    private function downloadAndStore(string $url, ExchangeSymbol $symbol, string $timeframe): ?array
    {
        $response = Http::timeout(90)
            ->withOptions(['stream' => false])
            ->get($url);

        if ($response->status() === 404) {
            return null;
        }

        if (! $response->successful()) {
            throw new RuntimeException(sprintf('Vision GET %s returned HTTP %d', $url, $response->status()));
        }

        $zipBody = $response->body();
        if ($zipBody === '') {
            throw new RuntimeException("Empty body from {$url}");
        }

        // Write to tmp, unzip, parse. Keeps memory bounded for large months.
        $tmpDir = storage_path('app/backtest-vision-tmp');
        if (! File::isDirectory($tmpDir)) {
            File::makeDirectory($tmpDir, 0o755, true);
        }

        $tmpZip = $tmpDir.'/'.uniqid('vision_', true).'.zip';
        File::put($tmpZip, $zipBody);

        try {
            $csvRows = $this->extractCsvRows($tmpZip);
        } finally {
            @unlink($tmpZip);
        }

        if (empty($csvRows)) {
            return ['inserted' => 0, 'earliest' => null, 'latest' => null];
        }

        return $this->upsertCandles($csvRows, $symbol, $timeframe);
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function extractCsvRows(string $zipPath): array
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException("Failed to open ZIP {$zipPath}");
        }

        if ($zip->numFiles === 0) {
            $zip->close();
            throw new RuntimeException("Empty ZIP archive {$zipPath}");
        }

        $csvName = $zip->getNameIndex(0);
        $csvContent = $zip->getFromName($csvName);
        $zip->close();

        if ($csvContent === false) {
            throw new RuntimeException("Failed to read CSV from {$zipPath}");
        }

        $rows = [];
        $lines = preg_split('/\r\n|\n|\r/', $csvContent);
        foreach ($lines as $line) {
            if ($line === '' || $line === null) {
                continue;
            }
            // Skip header if present (Binance Vision added headers to some files in 2025+).
            if (! is_numeric(mb_substr($line, 0, 1))) {
                continue;
            }
            $rows[] = str_getcsv($line, ',', '"', '\\');
        }

        return $rows;
    }

    /**
     * Upsert parsed CSV rows into the `candles` table. Chunked to keep
     * a single month's ~30k-720 row range manageable.
     *
     * @param  array<int, array<int, string>>  $csvRows
     * @return array{inserted: int, earliest: string|null, latest: string|null}
     */
    private function upsertCandles(array $csvRows, ExchangeSymbol $symbol, string $timeframe): array
    {
        $now = now();
        $earliestMs = null;
        $latestMs = null;
        $inserted = 0;

        foreach (array_chunk($csvRows, 500) as $chunk) {
            $rows = [];
            foreach ($chunk as $cols) {
                if (count($cols) < 6) {
                    continue;
                }

                // Vision archives use either seconds, milliseconds, or
                // microseconds depending on vintage. Normalise down to
                // seconds so our `candles.timestamp` matches the
                // existing convention used by FetchKlinesJob and the
                // candles table's unique index.
                $openTimeSec = $this->normaliseEpochToSeconds((int) $cols[0]);

                $earliestMs = $earliestMs === null ? $openTimeSec : min($earliestMs, $openTimeSec);
                $latestMs = $latestMs === null ? $openTimeSec : max($latestMs, $openTimeSec);

                $candleTime = Carbon::createFromTimestamp($openTimeSec, 'UTC');

                $rows[] = [
                    'exchange_symbol_id' => $symbol->id,
                    'timeframe' => $timeframe,
                    'open' => $cols[1],
                    'high' => $cols[2],
                    'low' => $cols[3],
                    'close' => $cols[4],
                    'volume' => $cols[5],
                    'timestamp' => $openTimeSec,
                    'candle_time_utc' => $candleTime->format('Y-m-d H:i:s'),
                    'candle_time_local' => $candleTime->copy()->setTimezone(config('app.timezone', 'UTC'))->format('Y-m-d H:i:s'),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if (empty($rows)) {
                continue;
            }

            // Upsert keyed by (exchange_symbol_id, timeframe, timestamp);
            // the existing candle rows observe this as an effective uniq.
            DB::table('candles')->upsert(
                $rows,
                ['exchange_symbol_id', 'timeframe', 'timestamp'],
                ['open', 'high', 'low', 'close', 'volume', 'candle_time_utc', 'candle_time_local', 'updated_at']
            );
            $inserted += count($rows);
        }

        return [
            'inserted' => $inserted,
            'earliest' => $earliestMs !== null ? Carbon::createFromTimestamp($earliestMs, 'UTC')->format('Y-m-d H:i:s') : null,
            'latest' => $latestMs !== null ? Carbon::createFromTimestamp($latestMs, 'UTC')->format('Y-m-d H:i:s') : null,
        ];
    }

    /**
     * Accept any Vision epoch (seconds / ms / us) and return seconds.
     * The `candles.timestamp` column stores seconds by project convention
     * (see FetchKlinesJob::normalizeEpochToSeconds).
     */
    private function normaliseEpochToSeconds(int $epoch): int
    {
        if ($epoch >= 1_000_000_000_000_000) {     // microseconds
            return intdiv($epoch, 1_000_000);
        }
        if ($epoch >= 1_000_000_000_000) {         // milliseconds
            return intdiv($epoch, 1000);
        }

        return $epoch;                             // already seconds
    }

    private function monthlyUrl(string $binanceSymbol, string $timeframe, Carbon $month): string
    {
        $ym = $month->format('Y-m');

        return sprintf(
            '%s/%s/%s/%s-%s-%s.zip',
            self::VISION_BASE_URL,
            $binanceSymbol,
            $timeframe,
            $binanceSymbol,
            $timeframe,
            $ym
        );
    }

    /**
     * Convert a Kraite ExchangeSymbol to the Binance-native concatenated
     * pair (e.g. "BTCUSDT"). Binance Vision uses the uppercase no-slash
     * convention across all futures endpoints.
     */
    private function binanceApiSymbol(ExchangeSymbol $symbol): string
    {
        return mb_strtoupper($symbol->token.$symbol->quote);
    }

    private function assertBinanceSymbol(ExchangeSymbol $symbol): void
    {
        $canonical = $symbol->apiSystem->canonical ?? null;
        if ($canonical !== 'binance') {
            throw new InvalidArgumentException(sprintf(
                'BinanceVisionCandleFetcher only supports Binance symbols — got "%s".',
                $canonical ?? 'unknown'
            ));
        }
    }

    /**
     * Is every closed candle for this (symbol, timeframe, month) already
     * in the DB? Compares COUNT vs the deterministic expected count —
     * `days_in_month * (24 / tf_hours)`. Cheaper than downloading + parsing
     * a monthly ZIP just to let upsert no-op.
     */
    private function isMonthFullyCovered(ExchangeSymbol $symbol, string $timeframe, Carbon $monthCursor): bool
    {
        $start = $monthCursor->copy()->startOfMonth()->getTimestamp();
        $end = $monthCursor->copy()->startOfMonth()->addMonth()->getTimestamp();

        $existing = DB::table('candles')
            ->where('exchange_symbol_id', $symbol->id)
            ->where('timeframe', $timeframe)
            ->where('timestamp', '>=', $start)
            ->where('timestamp', '<', $end)
            ->count();

        $tfHours = intdiv(CandleCoverageVerifier::INTERVAL_SECONDS[$timeframe], 3600);
        $expected = intdiv($monthCursor->daysInMonth * 24, $tfHours);

        return $existing >= $expected;
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
