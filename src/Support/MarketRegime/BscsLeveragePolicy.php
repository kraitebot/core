<?php

declare(strict_types=1);

namespace Kraite\Core\Support\MarketRegime;

use Kraite\Core\Support\MarketRegime\ValueObjects\BscsLeverageAdjustment;

/**
 * Applies the BSCS regime leverage ramp to an exchange-compatible base leverage.
 */
final readonly class BscsLeveragePolicy
{
    public function __construct(private BscsState $state) {}

    public function adjust(int $baseLeverage): BscsLeverageAdjustment
    {
        $ratio = RegimeLeverageMultiplier::for($this->state->score());

        return new BscsLeverageAdjustment(
            base: $baseLeverage,
            ratio: $ratio,
            effective: max(1, (int) floor($baseLeverage * $ratio)),
            score: $this->state->score(),
        );
    }
}
