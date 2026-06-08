<?php

declare(strict_types=1);

namespace Kraite\Core\Support\MarketRegime;

use Kraite\Core\Enums\RegimeBand;

/**
 * Discrete per-band position-count ramp (Phase 3).
 *
 * Scales the per-direction position-count cap DOWN as the BSCS regime
 * worsens, so fewer correlated stop-losses can fire together in a
 * drawdown. A distinct axis from margin (shrinks each position) and
 * leverage (moves the liquidation cliff): this limits HOW MANY positions
 * can bleed at once.
 *
 *   Calm (0-39)      → 1.00  (full configured count)
 *   Elevated (40-59) → 0.75  (config: market_regime.count_ratio.elevated)
 *   Fragile (60-79)  → 0.50  (config: market_regime.count_ratio.fragile)
 *   Critical (80+)   → 0.00  (no new opens)
 *   score null       → 1.00  (pre-first-compute, fail safe)
 *
 * Applied in `AssignBestTokensToPositionSlotsJob`:
 *
 *   band_cap  = floor(account_max_count × for($score))
 *   available = max(0, band_cap − currently_open)   // gate only; never force-closes
 */
final class RegimeCountMultiplier
{
    public static function for(?int $score): float
    {
        if ($score === null) {
            return 1.0;
        }

        return match (RegimeBand::fromScore($score)) {
            RegimeBand::Elevated => (float) (config('kraite.market_regime.count_ratio.elevated') ?? 0.75),
            RegimeBand::Fragile => (float) (config('kraite.market_regime.count_ratio.fragile') ?? 0.50),
            RegimeBand::Critical => 0.0,
            RegimeBand::Calm => 1.0,
        };
    }
}
