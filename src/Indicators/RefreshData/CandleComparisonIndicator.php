<?php

declare(strict_types=1);

namespace Kraite\Core\Indicators\RefreshData;

use Kraite\Core\Abstracts\BaseIndicator;
use Kraite\Core\Contracts\Indicators\DirectionIndicator;

final class CandleComparisonIndicator extends BaseIndicator implements DirectionIndicator
{
    public string $endpoint = 'candle';

    public function conclusion(): ?string
    {
        return $this->direction();
    }

    /**
     * Compares candle prices to determine direction.
     * Returns LONG if price increased from older to newer candle.
     * Returns SHORT if price decreased from older to newer candle.
     * Returns null if insufficient data.
     */
    public function direction(): ?string
    {
        // TAAPI returns candle data in columnar format:
        // {"close": [older, newer], "open": [older, newer], ...}
        // where index 0 = older candle, index 1 = newer candle
        $candles = $this->data;

        // Check if we have the close price array with at least 2 values
        if (! isset($candles['close']) || ! is_array($candles['close']) || count($candles['close']) < 2) {
            return null;
        }

        // Extract close prices: [0] = older, [1] = newer
        $olderPrice = $candles['close'][0] ?? null;
        $newerPrice = $candles['close'][1] ?? null;

        if ($olderPrice === null || $newerPrice === null) {
            return null;
        }

        // Compare: if newer > older = LONG (price increased)
        //          if newer < older = SHORT (price decreased)
        return $newerPrice > $olderPrice ? 'LONG' : 'SHORT';
    }
}
