<?php

declare(strict_types=1);

namespace Kraite\Core\Support\MarketRegime;

/**
 * Linear margin reduction across the BSCS Fragile band (60-79).
 *
 *   score < 60     → 1.0    (Calm / Elevated, no scaling)
 *   60 <= s <= 79  → linear ramp 1.0 → 0.5 across the band
 *   score >= 80    → 1.0    (gate-level block fires; scaler moot)
 *   score is null  → 1.0    (pre-first-compute, fail safe)
 *
 * Worked examples (default 60→79 with 50% max reduction):
 *   60 → 1.000     (0% reduction)
 *   65 → ≈0.868    (~13% reduction)
 *   70 → ≈0.737    (~26% reduction)
 *   75 → ≈0.605    (~39% reduction)
 *   79 → 0.500     (50% reduction)
 *
 * Bounds + max-reduction-pct read from
 * `config('kraite.market_regime.fragile')`. Operator can tune via
 * env without redeploy.
 *
 * Composes multiplicatively with `CrowdingMultiplier` in
 * `PreparePositionDataJob`:
 *
 *   final_margin = base_margin × FragileMarginMultiplier::for($score)
 *                              × CrowdingMultiplier::for($direction, $bookRisk)
 */
final class FragileMarginMultiplier
{
    public static function for(?int $score): float
    {
        if ($score === null) {
            return 1.0;
        }

        $lower = (int) (config('kraite.market_regime.fragile.lower_bound') ?? 60);
        $upper = (int) (config('kraite.market_regime.fragile.upper_bound') ?? 79);
        $maxReductionPct = (int) (config('kraite.market_regime.fragile.max_reduction_pct') ?? 50);

        if ($score < $lower) {
            return 1.0;
        }

        if ($score > $upper) {
            // Score is past the Fragile band — the gate's threshold
            // (default 80) blocks opens entirely, so the scaler is
            // never observed by callers in this regime. Return 1.0
            // for safety against misconfiguration.
            return 1.0;
        }

        $bandWidth = max(1, $upper - $lower);
        $progress = ($score - $lower) / $bandWidth;
        $reductionPct = $progress * $maxReductionPct;

        return 1.0 - ($reductionPct / 100.0);
    }
}
