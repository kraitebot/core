<?php

declare(strict_types=1);

namespace Kraite\Core\Support\MarketRegime;

use Kraite\Core\Enums\RegimeBand;

/**
 * Discrete per-band leverage ramp (Phase 3).
 *
 * Scales the leverage a position would otherwise open at DOWN as the
 * BSCS regime worsens, pushing the liquidation price further from entry
 * so a price gap can't liquidate the position before its stop-loss
 * fires. Stacks with `FragileMarginMultiplier` (which shrinks the margin
 * slice): the position is both smaller AND sits further from its
 * liquidation cliff.
 *
 *   Calm (0-39)      → 1.00  (full leverage)
 *   Elevated (40-59) → 0.66  (config: market_regime.leverage_ratio.elevated)
 *   Fragile (60-79)  → 0.50  (config: market_regime.leverage_ratio.fragile)
 *   Critical (80+)   → 1.00  (opens are blocked at this band, so moot)
 *   score null       → 1.00  (pre-first-compute, fail safe)
 *
 * Applied in `DetermineLeverageJob`:
 *
 *   final_leverage = max(1, floor(base_leverage × for($score)))
 */
final class RegimeLeverageMultiplier
{
    public static function for(?int $score): float
    {
        if ($score === null) {
            return 1.0;
        }

        return match (RegimeBand::fromScore($score)) {
            RegimeBand::Elevated => (float) (config('kraite.market_regime.leverage_ratio.elevated') ?? 0.66),
            RegimeBand::Fragile => (float) (config('kraite.market_regime.leverage_ratio.fragile') ?? 0.50),
            RegimeBand::Calm, RegimeBand::Critical => 1.0,
        };
    }
}
