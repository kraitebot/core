<?php

declare(strict_types=1);

namespace Kraite\Core\Support\MarketRegime;

/**
 * Directional crowding multiplier — DOWNSCALES margin on the side of
 * the book that's already crowded. Locked policy: empty side stays
 * NEUTRAL (1.0×) until a backtest validates an "open against the
 * crowd is safer" upscale across the 6 historical events.
 *
 * Rule:
 *
 *   if direction matches book.largestSide
 *      AND book.largestSideRatio >= 0.7:
 *
 *       interpolate 1.0 → 0.5 across ratio 0.7 → 1.0
 *
 *   else:
 *
 *       1.0  (NEUTRAL)
 *
 * Examples:
 *   ratio 0.70 → 1.00× (boundary inclusive)
 *   ratio 0.85 → 0.75× (midway)
 *   ratio 1.00 → 0.50× (purely one-sided)
 *
 * Why the empty side stays at 1.0×: opening AGAINST a crowded book
 * during fragile regime is contra-trade logic. If the cascade
 * finishes the move, the "empty side" position rides it deep into
 * drawdown before reaching TP — a much larger loss than the 1.0×
 * sizing intended.
 *
 * Composes multiplicatively with `FragileMarginMultiplier` in
 * `PreparePositionDataJob`.
 *
 * @see DirectionalBookRisk
 * @see FragileMarginMultiplier
 */
final class CrowdingMultiplier
{
    public static function for(string $direction, DirectionalBookRisk $book): float
    {
        $largestSide = $book->largestSide();
        $ratio = $book->largestSideRatio();

        if ($largestSide === 'BALANCED') {
            return 1.0;
        }

        // Opposite side of the crowded book → no scaling.
        if ($direction !== $largestSide) {
            return 1.0;
        }

        $crowdingThreshold = (float) (config('kraite.market_regime.crowding.threshold') ?? 0.7);
        $maxReductionPct = (float) (config('kraite.market_regime.crowding.max_reduction_pct') ?? 50);

        if ($ratio < $crowdingThreshold) {
            return 1.0;
        }

        $bandWidth = max(0.001, 1.0 - $crowdingThreshold);
        $progress = ($ratio - $crowdingThreshold) / $bandWidth;
        $reductionPct = $progress * $maxReductionPct;

        return 1.0 - ($reductionPct / 100.0);
    }
}
