<?php

declare(strict_types=1);

namespace Kraite\Core\Support\MarketRegime\ValueObjects;

/**
 * Base margin and its effective BSCS-scaled result with audit multipliers.
 */
final readonly class BscsMarginAdjustment
{
    public function __construct(
        private string $base,
        private float $fragileMultiplier,
        private float $crowdingMultiplier,
        private float $combinedMultiplier,
        private string $effective,
        private ?int $score,
    ) {}

    public function effective(): string
    {
        return $this->effective;
    }

    public function fragileMultiplier(): float
    {
        return $this->fragileMultiplier;
    }

    public function crowdingMultiplier(): float
    {
        return $this->crowdingMultiplier;
    }

    public function combinedMultiplier(): float
    {
        return $this->combinedMultiplier;
    }

    public function score(): ?int
    {
        return $this->score;
    }

    /** @return array{base: string, fragile_multiplier: float, crowding_multiplier: float, combined_multiplier: float, effective: string, bscs_score: int|null} */
    public function toArray(): array
    {
        return [
            'base' => $this->base,
            'fragile_multiplier' => $this->fragileMultiplier,
            'crowding_multiplier' => $this->crowdingMultiplier,
            'combined_multiplier' => $this->combinedMultiplier,
            'effective' => $this->effective,
            'bscs_score' => $this->score,
        ];
    }
}
