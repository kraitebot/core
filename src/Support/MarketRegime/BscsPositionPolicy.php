<?php

declare(strict_types=1);

namespace Kraite\Core\Support\MarketRegime;

use Kraite\Core\Models\Account;
use Kraite\Core\Support\MarketRegime\ValueObjects\BscsPositionAvailability;
use Kraite\Core\Support\MarketRegime\ValueObjects\BscsPositionCaps;

/**
 * Calculates account-specific directional position caps and availability.
 */
final readonly class BscsPositionPolicy
{
    public function __construct(
        private Account $account,
        private BscsState $state,
    ) {}

    public function max(): BscsPositionCaps
    {
        $ratio = RegimeCountMultiplier::for($this->state->score());
        $maximumLongs = $this->accountMaximum('total_positions_long');
        $maximumShorts = $this->accountMaximum('total_positions_short');

        return new BscsPositionCaps(
            maximumLongs: $maximumLongs,
            maximumShorts: $maximumShorts,
            effectiveLongs: (int) floor($maximumLongs * $ratio),
            effectiveShorts: (int) floor($maximumShorts * $ratio),
            ratio: $ratio,
        );
    }

    public function available(
        int $exchangeLongs,
        int $exchangeShorts,
        int $databaseLongs,
        int $databaseShorts,
    ): BscsPositionAvailability {
        $caps = $this->max();

        return new BscsPositionAvailability(
            maximumLongs: $caps->effectiveLongs(),
            maximumShorts: $caps->effectiveShorts(),
            currentLongs: max($exchangeLongs, $databaseLongs),
            currentShorts: max($exchangeShorts, $databaseShorts),
        );
    }

    private function accountMaximum(string $attribute): int
    {
        $value = $this->account->getAttribute($attribute);

        return is_numeric($value) ? (int) $value : 0;
    }
}
