<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Backtest;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Kraite\Core\Models\ExchangeSymbol;

/**
 * CandleCoverageVerifier
 *
 * Audit tool behind the admin UI's "Verify Coverage" button. Walks the
 * expected timestamp grid for (symbol × timeframe × window) against
 * what's actually in the `candles` table and reports presence, holes,
 * contiguity, and boundary freshness.
 *
 * A hole = a single timeframe-interval gap in the otherwise contiguous
 * timeline, e.g. a 1h timeframe missing 2026-04-15 14:00 between
 * 13:00 and 15:00. We DON'T report every missing candle outside the
 * [earliest, latest] bounds because those aren't holes, they're edges
 * of the archive and the fetchers determine how far back to go.
 *
 * The backtest simulator tolerates small holes (it just walks past
 * them), but the verifier exists so operators know when a big chunk
 * is missing — a backtest on a symbol with 40% hole rate isn't saying
 * anything useful.
 */
final class CandleCoverageVerifier
{
    private const SUPPORTED_TIMEFRAMES = ['1h', '4h', '12h', '1d'];

    public const INTERVAL_SECONDS = [
        '1h' => 3600,
        '4h' => 14400,
        '12h' => 43200,
        '1d' => 86400,
    ];

    /**
     * Report coverage for a given symbol + timeframe.
     *
     * @return array{
     *   timeframe: string,
     *   total_present: int,
     *   earliest: string|null,
     *   latest: string|null,
     *   span_hours: float|null,
     *   expected_if_contiguous: int,
     *   holes_count: int,
     *   holes_sample: array<int, array{from: string, to: string, missing: int}>,
     *   contiguity_percent: float,
     *   is_fresh: bool,
     *   staleness_hours: float|null
     * }
     */
    public function verify(ExchangeSymbol $symbol, string $timeframe): array
    {
        $this->assertSupportedTimeframe($timeframe);

        $present = DB::table('candles')
            ->where('exchange_symbol_id', $symbol->id)
            ->where('timeframe', $timeframe)
            ->orderBy('timestamp')
            ->pluck('timestamp')
            ->map(fn ($ts) => (int) $ts)
            ->all();

        if (empty($present)) {
            return $this->emptyReport($timeframe);
        }

        // `candles.timestamp` stores UNIX seconds by convention (see
        // FetchKlinesJob::normalizeEpochToSeconds). All arithmetic is
        // in seconds; only the final human-readable strings convert.
        $earliestSec = min($present);
        $latestSec = max($present);
        $intervalSec = self::INTERVAL_SECONDS[$timeframe];

        $expected = (int) floor(($latestSec - $earliestSec) / $intervalSec) + 1;
        $presentSet = array_flip($present);

        $holes = [];
        $holeCount = 0;
        for ($ts = $earliestSec; $ts <= $latestSec; $ts += $intervalSec) {
            if (! isset($presentSet[$ts])) {
                $holeCount++;
                if (count($holes) < 25) {
                    $holes[] = $ts;
                }
            }
        }

        $holesSample = $this->collapseHolesIntoRuns($holes, $intervalSec);

        $now = Carbon::now('UTC');
        $latestCarbon = Carbon::createFromTimestamp($latestSec, 'UTC');
        $stalenessHours = round($now->diffInMinutes($latestCarbon) / 60, 2);
        $isFresh = $stalenessHours <= (self::INTERVAL_SECONDS[$timeframe] / 3600) * 2;

        $contiguity = $expected > 0
            ? round((count($present) / $expected) * 100, 2)
            : 100.0;

        return [
            'timeframe' => $timeframe,
            'total_present' => count($present),
            'earliest' => Carbon::createFromTimestamp($earliestSec, 'UTC')->format('Y-m-d H:i:s'),
            'latest' => $latestCarbon->format('Y-m-d H:i:s'),
            'span_hours' => round(($latestSec - $earliestSec) / 3600, 2),
            'expected_if_contiguous' => $expected,
            'holes_count' => $holeCount,
            'holes_sample' => $holesSample,
            'contiguity_percent' => $contiguity,
            'is_fresh' => $isFresh,
            'staleness_hours' => $stalenessHours,
        ];
    }

    /**
     * Group consecutive missing timestamps into hole-runs so the UI
     * can show "[03:00 – 07:00] missing 5 candles" instead of five
     * separate rows. Caller gets at most the first 25 holes pre-grouping,
     * which collapses to <= 25 runs.
     *
     * @param  array<int, int>  $holes
     * @return array<int, array{from: string, to: string, missing: int}>
     */
    private function collapseHolesIntoRuns(array $holes, int $intervalSec): array
    {
        if (empty($holes)) {
            return [];
        }

        $runs = [];
        $currentStart = $holes[0];
        $currentEnd = $holes[0];

        foreach (array_slice($holes, 1) as $ts) {
            if ($ts === $currentEnd + $intervalSec) {
                $currentEnd = $ts;

                continue;
            }

            $runs[] = $this->runToArray($currentStart, $currentEnd, $intervalSec);
            $currentStart = $ts;
            $currentEnd = $ts;
        }

        $runs[] = $this->runToArray($currentStart, $currentEnd, $intervalSec);

        return $runs;
    }

    /**
     * @return array{from: string, to: string, missing: int}
     */
    private function runToArray(int $fromSec, int $toSec, int $intervalSec): array
    {
        return [
            'from' => Carbon::createFromTimestamp($fromSec, 'UTC')->format('Y-m-d H:i:s'),
            'to' => Carbon::createFromTimestamp($toSec, 'UTC')->format('Y-m-d H:i:s'),
            'missing' => (int) floor(($toSec - $fromSec) / $intervalSec) + 1,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyReport(string $timeframe): array
    {
        return [
            'timeframe' => $timeframe,
            'total_present' => 0,
            'earliest' => null,
            'latest' => null,
            'span_hours' => null,
            'expected_if_contiguous' => 0,
            'holes_count' => 0,
            'holes_sample' => [],
            'contiguity_percent' => 0.0,
            'is_fresh' => false,
            'staleness_hours' => null,
        ];
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
