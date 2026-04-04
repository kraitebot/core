<?php

declare(strict_types=1);

namespace Kraite\Core\Indicators\RefreshData;

use Kraite\Core\Abstracts\BaseIndicator;
use Kraite\Core\Contracts\Indicators\DirectionIndicator;

/**
 * Bollinger Bands Indicator
 *
 * Measures volatility and price position relative to bands.
 *
 * TAAPI Response (with results=2):
 * {"valueUpperBand": [older, newer], "valueMiddleBand": [older, newer], "valueLowerBand": [older, newer]}
 *
 * Logic (uses candle close from the data):
 * - LONG: Price near or below lower band (oversold / mean reversion)
 * - SHORT: Price near or above upper band (overbought / mean reversion)
 * - null: Price within bands, no clear signal
 */
final class BollingerBandsIndicator extends BaseIndicator implements DirectionIndicator
{
    public string $endpoint = 'bbands';

    public function conclusion(): ?string
    {
        return $this->direction();
    }

    public function direction(): ?string
    {
        $upper = $this->data['valueUpperBand'] ?? null;
        $middle = $this->data['valueMiddleBand'] ?? null;
        $lower = $this->data['valueLowerBand'] ?? null;

        if (! is_array($upper) || ! is_array($lower) || ! is_array($middle)) {
            return null;
        }

        if (count($upper) < 2 || count($lower) < 2 || count($middle) < 2) {
            return null;
        }

        $currUpper = $upper[1];
        $currLower = $lower[1];
        $currMiddle = $middle[1];

        if ($currUpper === null || $currLower === null || $currMiddle === null) {
            return null;
        }

        $bandWidth = $currUpper - $currLower;

        if ($bandWidth <= 0) {
            return null;
        }

        // Calculate how close price is to bands using middle band as proxy
        // Lower 20% of band range → LONG (near lower band)
        // Upper 80% of band range → SHORT (near upper band)
        $position = ($currMiddle - $currLower) / $bandWidth;

        if ($position <= 0.25) {
            return 'LONG';
        }

        if ($position >= 0.75) {
            return 'SHORT';
        }

        return null;
    }
}
