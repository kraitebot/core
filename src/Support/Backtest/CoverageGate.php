<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Backtest;

use Carbon\Carbon;
use Kraite\Core\Enums\BacktestTimeframe;

/**
 * CoverageGate
 *
 * The single source of truth for "is this candle data trustworthy enough to
 * grade a token and let an operator approve it?". A backtest grade drives a
 * real-money risk decision, so the gate is deliberately stricter than the
 * advisory `CandleCoverageVerifier::is_fresh` (which uses a loose 2× interval
 * window): here FRESH means the table holds the last CLOSED candle (1× interval).
 *
 * Both the dispatcher-side `VerifyCoverageResultStep` and the admin-side
 * `run` / `toggleApproval` gates call this so the verdict is identical
 * everywhere.
 */
final class CoverageGate
{
    /**
     * Evaluate a CandleCoverageVerifier report against the risk gate.
     *
     * @param  array<string, mixed>  $coverage  Output of CandleCoverageVerifier::verify()
     * @return array{ready: bool, fresh: bool, complete: bool, has_data: bool, reason: string|null}
     */
    public static function evaluate(
        array $coverage,
        string $timeframe,
        ?int $maxMonths = null,
        ?int $requestedSinceTimestamp = null,
    ): array {
        $backtestTimeframe = BacktestTimeframe::tryFrom($timeframe);

        if ($backtestTimeframe === null) {
            return [
                'ready' => false,
                'fresh' => false,
                'complete' => false,
                'has_data' => false,
                'reason' => "unsupported timeframe '{$timeframe}'",
            ];
        }

        $intervalSec = $backtestTimeframe->seconds();

        $presentValue = $coverage['total_present'] ?? 0;
        $latestValue = $coverage['latest'] ?? null;
        $holesValue = $coverage['holes_count'] ?? 0;
        $contiguityValue = $coverage['contiguity_percent'] ?? 0.0;

        $present = is_numeric($presentValue) ? (int) $presentValue : 0;
        $latest = is_string($latestValue) && $latestValue !== '' ? $latestValue : null;
        $holes = is_numeric($holesValue) ? (int) $holesValue : 0;
        $contiguity = is_numeric($contiguityValue) ? (float) $contiguityValue : 0.0;

        // The candle that closed one interval ago. The currently-forming candle
        // is excluded — we never require it.
        $lastClosedTs = intdiv(time(), $intervalSec) * $intervalSec - $intervalSec;
        $latestTs = $latest !== null ? Carbon::parse($latest, 'UTC')->getTimestamp() : 0;

        $hasData = $present >= 1;
        $fresh = $latestTs >= $lastClosedTs;
        $historyCovered = $maxMonths === null || BacktestHistoryWindow::covers(
            $coverage,
            $timeframe,
            $maxMonths,
            $requestedSinceTimestamp,
        );
        // 99% (not 100%) keeps the continuity check tolerant of small edge
        // effects. Callers that request a concrete history window still require
        // the earliest candle to reach that boundary.
        $contiguous = $holes === 0 && $contiguity >= 99.0;
        $complete = $contiguous && $historyCovered;
        $ready = $hasData && $fresh && $complete;

        $reasons = [];
        if (! $hasData) {
            $reasons[] = 'no candles in the table';
        }
        if (! $fresh) {
            $reasons[] = sprintf(
                'stale — latest candle %s, need ≥ %s UTC',
                $latest ?? 'none',
                Carbon::createFromTimestamp($lastClosedTs, 'UTC')->format('Y-m-d H:i'),
            );
        }
        if (! $contiguous) {
            $reasons[] = sprintf('%d gap%s · %.1f%% contiguity', $holes, $holes === 1 ? '' : 's', $contiguity);
        }
        if ($maxMonths !== null && ! $historyCovered) {
            $requiredSince = BacktestHistoryWindow::requiredSinceTimestamp(
                $timeframe,
                $maxMonths,
                $requestedSinceTimestamp,
            );
            $reasons[] = sprintf(
                'thin history — earliest %s, need ≤ %s UTC',
                is_string($coverage['earliest'] ?? null) ? $coverage['earliest'] : 'none',
                $requiredSince !== null
                    ? Carbon::createFromTimestamp($requiredSince, 'UTC')->format('Y-m-d H:i')
                    : 'requested boundary',
            );
        }

        return [
            'ready' => $ready,
            'fresh' => $fresh,
            'complete' => $complete,
            'has_data' => $hasData,
            'reason' => $ready ? null : implode('; ', $reasons),
        ];
    }
}
