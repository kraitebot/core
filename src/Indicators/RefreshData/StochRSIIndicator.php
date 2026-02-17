<?php

declare(strict_types=1);

namespace Kraite\Core\Indicators\RefreshData;

use Kraite\Core\Abstracts\BaseIndicator;
use Kraite\Core\Contracts\Indicators\DirectionIndicator;

/**
 * Stochastic RSI Indicator
 *
 * Combines Stochastic oscillator with RSI for more sensitive momentum detection.
 * Cross-exchange proof: uses only price data (close prices), no volume.
 *
 * TAAPI Response (with results=2):
 * {"valueFastK": [older, newer], "valueFastD": [older, newer]}
 *
 * Logic:
 * - LONG: FastK crosses above FastD (bullish) AND not overbought (FastK < 80)
 * - SHORT: FastK crosses below FastD (bearish) AND not oversold (FastK > 20)
 * - null: No clear crossover or extreme conditions
 */
final class StochRSIIndicator extends BaseIndicator implements DirectionIndicator
{
    public string $endpoint = 'stochrsi';

    public function conclusion(): ?string
    {
        return $this->direction();
    }

    public function direction(): ?string
    {
        $fastK = $this->data['valueFastK'] ?? null;
        $fastD = $this->data['valueFastD'] ?? null;

        // Validate we have arrays with at least 2 values for crossover detection
        if (! is_array($fastK) || ! is_array($fastD) || count($fastK) < 2 || count($fastD) < 2) {
            return null;
        }

        $prevFastK = $fastK[0] ?? null;
        $currFastK = $fastK[1] ?? null;
        $prevFastD = $fastD[0] ?? null;
        $currFastD = $fastD[1] ?? null;

        if ($prevFastK === null || $currFastK === null || $prevFastD === null || $currFastD === null) {
            return null;
        }

        // Bullish crossover: FastK crosses above FastD
        // Previous: FastK <= FastD, Current: FastK > FastD
        // Also check not overbought (FastK < 80)
        if ($prevFastK <= $prevFastD && $currFastK > $currFastD && $currFastK < 80) {
            return 'LONG';
        }

        // Bearish crossover: FastK crosses below FastD
        // Previous: FastK >= FastD, Current: FastK < FastD
        // Also check not oversold (FastK > 20)
        if ($prevFastK >= $prevFastD && $currFastK < $currFastD && $currFastK > 20) {
            return 'SHORT';
        }

        return null;
    }
}
