<?php

declare(strict_types=1);

namespace Kraite\Core\Concerns\Account;

use Kraite\Core\Models\ApiSystem;

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
     * Boolean check: is this account currently eligible to drive a Binance
     * user-data WebSocket stream?
     *
     * Used by the keepalive cron when iterating loaded `binance_listen_keys`
     * rows — the cron needs a per-instance answer ("is this row's account
     * still eligible?") that the query-side scope can't provide. Both
     * predicates resolve to the same conditions; centralising here keeps
     * them aligned.
     *
     * Pairs with `Account::scopeEligibleForBinanceUserDataStream()` for
     * the query-side filter.
     */
    public function isEligibleForBinanceUserDataStream(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->binance_api_key === null) {
            return false;
        }

        // Mirror the scope-side requirement: both halves of the
        // credential pair are required. Pre-fix, an account with a
        // key but no secret would pass this predicate and the daemon
        // would retry-loop against Binance auth on every keepalive.
        if ($this->binance_api_secret === null) {
            return false;
        }

        $binanceId = ApiSystem::where('canonical', 'binance')->value('id');
        if ($binanceId === null) {
            return false;
        }

        return $this->api_system_id === $binanceId;
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
