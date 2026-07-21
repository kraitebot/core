<?php

declare(strict_types=1);

namespace Kraite\Core\Support\MarketRegime;

use Kraite\Core\Models\Account;

/**
 * Applies one global BSCS state consistently to one account.
 */
final readonly class BscsAccountContext
{
    public function __construct(
        private Account $account,
        private BscsState $state,
    ) {}

    public function state(): BscsState
    {
        return $this->state;
    }

    public function details(): BscsState
    {
        return $this->state;
    }

    public function opening(): BscsOpeningPolicy
    {
        return new BscsOpeningPolicy($this->state);
    }

    public function positions(): BscsPositionPolicy
    {
        return new BscsPositionPolicy($this->account, $this->state);
    }

    public function leverage(): BscsLeveragePolicy
    {
        return new BscsLeveragePolicy($this->state);
    }

    public function margin(): BscsMarginPolicy
    {
        return new BscsMarginPolicy($this->state);
    }
}
