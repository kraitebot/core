<?php

declare(strict_types=1);

namespace Kraite\Core\Support\TokenScoring;

/**
 * Multiplier in [0, 1] that penalises symbols whose rolling
 * correlation with BTC is jittery across the lookback windows.
 *
 * Input is the standard deviation of the per-window correlation
 * series for one timeframe. Lower std-dev = more reliable signal =
 * higher multiplier; high std-dev = noisy correlation = downweighted.
 *
 * Graceful degrade: null or non-positive stability → 1.0 so a
 * symbol is never penalised for the absence of data. Out-of-range
 * stability values are clamped (a std-dev > 0.5 already collapses
 * the multiplier to 0).
 */
final class CorrelationStabilityWeight
{
    public static function for(?float $stability): float
    {
        if ($stability === null || $stability <= 0.0) {
            return 1.0;
        }

        return max(0.0, 1.0 - 2.0 * $stability);
    }
}
