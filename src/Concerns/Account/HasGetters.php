<?php

declare(strict_types=1);

namespace Kraite\Core\Concerns\Account;

trait HasGetters
{
    /**
     * Maximum position slots for this account (LONGs + SHORTs).
     */
    public function maxPositionSlots(): int
    {
        return $this->total_positions_long + $this->total_positions_short;
    }
}
