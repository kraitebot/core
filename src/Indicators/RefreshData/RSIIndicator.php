<?php

declare(strict_types=1);

namespace Kraite\Core\Indicators\RefreshData;

use Kraite\Core\Abstracts\BaseIndicator;
use Kraite\Core\Contracts\Indicators\DirectionIndicator;

/**
 * RSI (Relative Strength Index) Indicator
 *
 * Classic momentum oscillator measuring speed and magnitude of price changes.
 *
 * TAAPI Response (with results=2):
 * {"value": [older, newer]}
 *
 * Logic:
 * - LONG: RSI < 30 (oversold) OR RSI crossing above 50 from below
 * - SHORT: RSI > 70 (overbought) OR RSI crossing below 50 from above
 * - null: No clear signal (between 30-70 with no crossover)
 */
final class RSIIndicator extends BaseIndicator implements DirectionIndicator
{
    public string $endpoint = 'rsi';

    public function conclusion(): ?string
    {
        return $this->direction();
    }

    public function direction(): ?string
    {
        $values = $this->data['value'] ?? null;

        if (! is_array($values) || count($values) < 2) {
            return null;
        }

        $prev = $values[0];
        $curr = $values[1];

        if ($prev === null || $curr === null) {
            return null;
        }

        // Oversold → bullish
        if ($curr < 30) {
            return 'LONG';
        }

        // Overbought → bearish
        if ($curr > 70) {
            return 'SHORT';
        }

        // Crossing above 50 midline → bullish momentum
        if ($prev < 50 && $curr >= 50) {
            return 'LONG';
        }

        // Crossing below 50 midline → bearish momentum
        if ($prev > 50 && $curr <= 50) {
            return 'SHORT';
        }

        return null;
    }
}
