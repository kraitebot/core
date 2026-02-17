<?php

declare(strict_types=1);

namespace Kraite\Core\Contracts\Indicators;

/**
 * Interface for indicators that determine market direction.
 *
 * Direction indicators analyze market data to conclude whether
 * the trend is bullish (LONG) or bearish (SHORT).
 */
interface DirectionIndicator
{
    /**
     * Determines the market direction based on indicator analysis.
     *
     * @return string|null 'LONG' for bullish trend, 'SHORT' for bearish trend, null if inconclusive
     */
    public function direction(): ?string;
}
