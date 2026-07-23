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
    public static function evaluate(array $coverage, string $timeframe): array
    {
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

        $present = (int) ($coverage['total_present'] ?? 0);
        $latest = $coverage['latest'] ?? null;
        $holes = (int) ($coverage['holes_count'] ?? 0);
        $contiguity = (float) ($coverage['contiguity_percent'] ?? 0.0);

        // The candle that closed one interval ago. The currently-forming candle
        // is excluded — we never require it.
        $lastClosedTs = intdiv(time(), $intervalSec) * $intervalSec - $intervalSec;
        $latestTs = $latest !== null ? Carbon::parse((string) $latest, 'UTC')->getTimestamp() : 0;

        $hasData = $present >= 1;
        $fresh = $latestTs >= $lastClosedTs;
        // 99% (not 100%) tolerates a token listed mid-window whose full history
        // cannot exist — that is not a data-quality fault.
        $complete = $holes === 0 && $contiguity >= 99.0;
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
        if (! $complete) {
            $reasons[] = sprintf('%d gap%s · %.1f%% contiguity', $holes, $holes === 1 ? '' : 's', $contiguity);
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
