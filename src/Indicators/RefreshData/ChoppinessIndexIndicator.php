<?php

declare(strict_types=1);

namespace Kraite\Core\Indicators\RefreshData;

use Kraite\Core\Abstracts\BaseIndicator;
use Kraite\Core\Contracts\Indicators\ValidationIndicator;
use Kraite\Core\Support\Math;

/**
 * ChoppinessIndexIndicator
 *
 * Wraps TAAPI's `chop` endpoint. Output is a single 0–100 score
 * measuring how trendless / range-bound the recent candles are:
 *
 *   CHOP ≥ ~61.8 → choppy / ranging (avoid directional trades)
 *   CHOP ≤ ~38.2 → strongly trending
 *   between     → borderline
 *
 * Plugged into the conclude pipeline as a ValidationIndicator so it
 * invalidates the timeframe BEFORE any direction vote is counted. The
 * unanimous-agreement gate in ConcludeSymbolDirectionAtTimeframeJob
 * is expensive when it runs on chop — five direction indicators fire,
 * disagree, and the symbol lands in `has_invalid_indicator_direction=1`
 * anyway. Bailing at CHOP is strictly cheaper and lets the timeframe
 * walker advance to a higher timeframe where trend may re-emerge.
 *
 * Threshold chosen at 55 — conservative (above the classic 38.2/61.8
 * Fibonacci band midpoint) to favour "trade only clear trends" over
 * "trade borderline regimes". Tune in the seeded `parameters` column
 * or extend this class to pull from config.
 */
final class ChoppinessIndexIndicator extends BaseIndicator implements ValidationIndicator
{
    /**
     * Chop score at or above this value marks the timeframe as choppy
     * and the symbol is invalidated at this timeframe (walker advances).
     */
    private const CHOP_THRESHOLD = 55.0;

    public string $endpoint = 'chop';

    public function conclusion(): bool
    {
        return $this->isValid();
    }

    public function isValid(): bool
    {
        $value = $this->data['value'] ?? null;

        if (is_array($value)) {
            $value = $value === [] ? null : $value[array_key_last($value)];
        }

        // Missing / non-numeric payload → permissive pass. We don't want
        // a TAAPI hiccup on the chop endpoint to suppress otherwise
        // healthy direction conclusions. The direction vote downstream
        // still runs its own unanimous-agreement gate.
        if (! is_numeric($value)) {
            return true;
        }

        return Math::lt((string) $value, (string) self::CHOP_THRESHOLD);
    }
}
