<?php

declare(strict_types=1);

namespace Kraite\Core\Support\MarketRegime;

/**
 * Answers only the BSCS portion of the new-position opening decision.
 */
final readonly class BscsOpeningPolicy
{
    public function __construct(private BscsState $state) {}

    public function allowsNewPositions(): bool
    {
        return ! $this->state->shouldBlockOpens();
    }
}
