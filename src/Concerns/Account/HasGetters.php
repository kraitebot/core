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

    /**
     * True when the account's exchange is in hedge mode (Binance:
     * `dualSidePosition=true`, Bybit: `positionIdx=1|2`, etc.). The
     * mapper layer reads this to decide whether to send `positionSide=
     * LONG/SHORT` (hedge) or omit it / send BOTH plus `reduceOnly` on
     * closes (one-way).
     */
    public function isHedgeMode(): bool
    {
        return (bool) $this->on_hedge_mode;
    }

    /**
     * Inverse of isHedgeMode(). One-way mode requires omitting
     * positionSide and explicitly marking close-intent orders with
     * reduceOnly=true (or closePosition=true for algo SL/TP).
     */
    public function isOneWayMode(): bool
    {
        return ! $this->isHedgeMode();
    }
}
