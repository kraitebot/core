<?php

declare(strict_types=1);

namespace Kraite\Core\Support\MarketRegime\ValueObjects;

/**
 * Base leverage and its effective BSCS-scaled result.
 */
final readonly class BscsLeverageAdjustment
{
    public function __construct(
        private int $base,
        private float $ratio,
        private int $effective,
        private ?int $score,
    ) {}

    public function effective(): int
    {
        return $this->effective;
    }

    public function ratio(): float
    {
        return $this->ratio;
    }

    public function score(): ?int
    {
        return $this->score;
    }

    /** @return array{base: int, ratio: float, effective: int, bscs_score: int|null} */
    public function toArray(): array
    {
        return [
            'base' => $this->base,
            'ratio' => $this->ratio,
            'effective' => $this->effective,
            'bscs_score' => $this->score,
        ];
    }
}
