<?php

declare(strict_types=1);

namespace Kraite\Core\Support\MarketRegime\ValueObjects;

/**
 * Directional capacity remaining after reconciling exchange and database positions.
 */
final readonly class BscsPositionAvailability
{
    public function __construct(
        private int $maximumLongs,
        private int $maximumShorts,
        private int $currentLongs,
        private int $currentShorts,
    ) {}

    public function availableLongs(): int
    {
        return max(0, $this->maximumLongs - $this->currentLongs);
    }

    public function availableShorts(): int
    {
        return max(0, $this->maximumShorts - $this->currentShorts);
    }

    public function maximumLongs(): int
    {
        return $this->maximumLongs;
    }

    public function maximumShorts(): int
    {
        return $this->maximumShorts;
    }

    public function currentLongs(): int
    {
        return $this->currentLongs;
    }

    public function currentShorts(): int
    {
        return $this->currentShorts;
    }

    /** @return array{long: array{available: int, current: int, maximum: int}, short: array{available: int, current: int, maximum: int}} */
    public function toArray(): array
    {
        return [
            'long' => [
                'available' => $this->availableLongs(),
                'current' => $this->currentLongs,
                'maximum' => $this->maximumLongs,
            ],
            'short' => [
                'available' => $this->availableShorts(),
                'current' => $this->currentShorts,
                'maximum' => $this->maximumShorts,
            ],
        ];
    }
}
