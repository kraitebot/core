<?php

declare(strict_types=1);

namespace Kraite\Core\Support\MarketRegime;

use Kraite\Core\Support\MarketRegime\ValueObjects\BscsMarginAdjustment;
use Kraite\Core\Support\Math;

/**
 * Applies fragile-regime and directional-crowding reductions to margin.
 */
final readonly class BscsMarginPolicy
{
    public function __construct(private BscsState $state) {}

    /** @param 'LONG'|'SHORT' $direction */
    public function adjust(string $baseMargin, string $direction): BscsMarginAdjustment
    {
        $fragileMultiplier = FragileMarginMultiplier::for($this->state->score());
        $crowdingMultiplier = CrowdingMultiplier::for($direction, $this->state->portfolioRisk());
        $combinedMultiplier = $fragileMultiplier * $crowdingMultiplier;
        $effectiveMargin = Math::equal($combinedMultiplier, 1)
            ? $baseMargin
            : Math::mul($baseMargin, (string) $combinedMultiplier);

        return new BscsMarginAdjustment(
            base: $baseMargin,
            fragileMultiplier: $fragileMultiplier,
            crowdingMultiplier: $crowdingMultiplier,
            combinedMultiplier: $combinedMultiplier,
            effective: $effectiveMargin,
            score: $this->state->score(),
        );
    }
}
