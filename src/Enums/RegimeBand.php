<?php

declare(strict_types=1);

namespace Kraite\Core\Enums;

/**
 * Black Swan Composite Score (BSCS) regime bands.
 *
 * BSCS is a portfolio-level signal in the range [0, 100] computed by
 * `Kraite\Core\Support\MarketRegime\RegimeCalculator`. The score is
 * mapped into one of four bands; each band drives a distinct trading
 * posture:
 *
 *   - **Calm** (0-39): baseline, no posture change.
 *   - **Elevated** (40-59): monitor only, no automatic action.
 *   - **Fragile** (60-79): linear margin-slice reduction on new opens
 *     (Phase 2 — read-only in Phase 1).
 *   - **Critical** (80-100): block new opens (Phase 2 — read-only in Phase 1).
 *
 * Spec: `~/docs/kraite/black-swan-logic.md` "Regime Bands" + locked
 * decision #1 (block threshold = 80).
 */
enum RegimeBand: string
{
    case Calm = 'calm';
    case Elevated = 'elevated';
    case Fragile = 'fragile';
    case Critical = 'critical';

    public static function fromScore(int $score): self
    {
        return match (true) {
            $score >= 80 => self::Critical,
            $score >= 60 => self::Fragile,
            $score >= 40 => self::Elevated,
            default => self::Calm,
        };
    }

    /**
     * Whether this band blocks new opens. Phase 2 wires this into
     * `HasTradingGuards::canOpenPositions()`. Phase 1 is read-only.
     */
    public function blocksOpens(): bool
    {
        return $this === self::Critical;
    }

    /**
     * Whether this band reduces the per-position margin slice on new
     * opens via the linear scaler (Phase 2). Phase 1 is read-only.
     */
    public function reducesMarginSlice(): bool
    {
        return $this === self::Fragile;
    }
}
