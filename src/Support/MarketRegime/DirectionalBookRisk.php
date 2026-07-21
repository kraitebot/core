<?php

declare(strict_types=1);

namespace Kraite\Core\Support\MarketRegime;

use Kraite\Core\Models\Position;
use Kraite\Core\Support\Math;

/**
 * Portfolio-shape DTO. Reads OPEN positions across all accounts and
 * computes which side of the book carries more risk, measured by
 * notional exposure (margin × leverage), not raw count.
 *
 * Two consumers:
 *
 *   - `BscsState::portfolioRisk()` returns this for dashboard
 *     rendering ("LONG crowded — 8/10 positions, 73% of margin").
 *
 *   - Phase 2.1C's directional crowding multiplier in
 *     `PreparePositionDataJob` reads `largestSide()` and
 *     `largestSideRatio()` to downscale margin on the crowded side
 *     when BSCS is in Fragile (no upscale on the empty side without
 *     backtest evidence — this is a locked policy, see the spec).
 *
 * Pure read-side. Constructed via `current()` factory which queries
 * positions in one round-trip; immutable instance values.
 *
 * Position scope uses the existing `Position::opened()` scope —
 * statuses [opening, waping, active, new, closing, cancelling, syncing].
 * Closed / cancelled / failed positions are out by definition.
 *
 * @see ~/docs/kraite/black-swan-logic.md (Phase 2.1B)
 */
final class DirectionalBookRisk
{
    private function __construct(
        private readonly int $longOpenCount,
        private readonly int $shortOpenCount,
        private readonly string $longMarginAtRisk,
        private readonly string $shortMarginAtRisk,
    ) {}

    /**
     * Build the DTO from the live `positions` table. Single query
     * round-trip — no per-account fan-out, no per-direction subqueries.
     */
    public static function current(): self
    {
        $rows = Position::query()
            ->opened()
            ->whereNotNull('direction')
            ->selectRaw('direction, COUNT(*) as open_count')
            ->selectRaw('COALESCE(SUM(COALESCE(margin, 0) * COALESCE(leverage, 0)), 0) as margin_at_risk')
            ->groupBy('direction')
            ->toBase()
            ->get();

        $longCount = 0;
        $shortCount = 0;
        $longMargin = '0';
        $shortMargin = '0';

        foreach ($rows as $row) {
            $openCount = $row->open_count ?? null;
            $marginAtRisk = $row->margin_at_risk ?? null;

            if (! is_numeric($openCount) || ! is_numeric($marginAtRisk)) {
                continue;
            }

            if ($row->direction === 'LONG') {
                $longCount = (int) $openCount;
                $longMargin = (string) $marginAtRisk;

                continue;
            }

            if ($row->direction === 'SHORT') {
                $shortCount = (int) $openCount;
                $shortMargin = (string) $marginAtRisk;
            }
        }

        return new self($longCount, $shortCount, $longMargin, $shortMargin);
    }

    public function longOpenCount(): int
    {
        return $this->longOpenCount;
    }

    public function shortOpenCount(): int
    {
        return $this->shortOpenCount;
    }

    public function longMarginAtRisk(): string
    {
        return $this->longMarginAtRisk;
    }

    public function shortMarginAtRisk(): string
    {
        return $this->shortMarginAtRisk;
    }

    /**
     * Which side dominates by NOTIONAL exposure (margin × leverage),
     * not raw position count. A single 50× leveraged LONG outweighs
     * five 1× SHORTs.
     *
     * @return 'LONG'|'SHORT'|'BALANCED'
     */
    public function largestSide(): string
    {
        if (Math::gt($this->longMarginAtRisk, $this->shortMarginAtRisk)) {
            return 'LONG';
        }

        if (Math::gt($this->shortMarginAtRisk, $this->longMarginAtRisk)) {
            return 'SHORT';
        }

        return 'BALANCED';
    }

    /**
     * Fraction of total notional concentrated on the largest side.
     *
     *   0.5  = perfectly balanced
     *   1.0  = 100% one-sided
     *   0.7  = 70/30 split (Phase 2.1C will start crowding-downscale here)
     */
    public function largestSideRatio(): float
    {
        $total = Math::add($this->longMarginAtRisk, $this->shortMarginAtRisk);
        if (! Math::gt($total, '0')) {
            return 0.5;
        }

        $larger = Math::gt($this->longMarginAtRisk, $this->shortMarginAtRisk)
            ? $this->longMarginAtRisk
            : $this->shortMarginAtRisk;

        return (float) Math::div($larger, $total, 6);
    }

    /**
     * Signed bias: +1.0 = all LONG, -1.0 = all SHORT, 0.0 = balanced.
     * Useful when downstream code needs a single scalar to compare
     * against a threshold (e.g. "only block opens if bias above 0.6").
     */
    public function netDirectionalBias(): float
    {
        $total = Math::add($this->longMarginAtRisk, $this->shortMarginAtRisk);
        if (! Math::gt($total, '0')) {
            return 0.0;
        }

        $delta = Math::sub($this->longMarginAtRisk, $this->shortMarginAtRisk);

        return (float) Math::div($delta, $total, 6);
    }

    /**
     * Lossless dashboard payload. Composes into BscsState::toArray()
     * under the `portfolio_risk` key.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'long_open_count' => $this->longOpenCount,
            'short_open_count' => $this->shortOpenCount,
            'long_margin_at_risk' => $this->longMarginAtRisk,
            'short_margin_at_risk' => $this->shortMarginAtRisk,
            'largest_side' => $this->largestSide(),
            'largest_side_ratio' => $this->largestSideRatio(),
            'net_directional_bias' => $this->netDirectionalBias(),
        ];
    }
}
