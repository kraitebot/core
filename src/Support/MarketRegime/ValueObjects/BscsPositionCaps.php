<?php

declare(strict_types=1);

namespace Kraite\Core\Support\MarketRegime\ValueObjects;

/**
 * Saved and BSCS-adjusted directional position limits for one account.
 */
final readonly class BscsPositionCaps
{
    public function __construct(
        private int $maximumLongs,
        private int $maximumShorts,
        private int $effectiveLongs,
        private int $effectiveShorts,
        private float $ratio,
    ) {}

    public function maximumLongs(): int
    {
        return $this->maximumLongs;
    }

    public function maximumShorts(): int
    {
        return $this->maximumShorts;
    }

    public function effectiveLongs(): int
    {
        return $this->effectiveLongs;
    }

    public function effectiveShorts(): int
    {
        return $this->effectiveShorts;
    }

    public function effectiveTotal(): int
    {
        return $this->effectiveLongs + $this->effectiveShorts;
    }

    public function ratio(): float
    {
        return $this->ratio;
    }

    /** @return array{long: array{effective: int, maximum: int}, short: array{effective: int, maximum: int}, ratio_percent: int} */
    public function toArray(): array
    {
        return [
            'long' => [
                'effective' => $this->effectiveLongs,
                'maximum' => $this->maximumLongs,
            ],
            'short' => [
                'effective' => $this->effectiveShorts,
                'maximum' => $this->maximumShorts,
            ],
            'ratio_percent' => (int) round($this->ratio * 100),
        ];
    }
}
