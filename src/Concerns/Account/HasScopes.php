<?php

declare(strict_types=1);

namespace Kraite\Core\Concerns\Account;

use Illuminate\Database\Eloquent\Builder;
use Kraite\Core\Models\ApiSystem;

trait HasScopes
{
    public function scopeActive(Builder $query)
    {
        return $query->where('accounts.is_active', true);
    }

    public function scopeTradeable(Builder $query)
    {
        return $query->where('accounts.can_trade', true);
    }

    /**
     * Filters accounts that are currently eligible to drive a Binance
     * user-data WebSocket stream.
     *
     * Centralised so the daemon's account-discovery query and the
     * keepalive cron's row-by-row eligibility check stay aligned. Adding
     * a new condition (e.g. a future `stream_disabled` flag, a
     * subscription-tier gate) is a one-line edit here, not a hunt
     * across every site that filters Binance accounts.
     *
     * Conditions:
     *   - `accounts.is_active = true`
     *   - `accounts.api_system_id` = Binance system id
     *   - `accounts.binance_api_key IS NOT NULL`
     *
     * Pairs with `Account::isEligibleForBinanceUserDataStream()` for the
     * boolean check on already-loaded instances.
     */
    public function scopeEligibleForBinanceUserDataStream(Builder $query): Builder
    {
        $binanceId = ApiSystem::where('canonical', 'binance')->value('id');

        if ($binanceId === null) {
            // Fresh install / mid-migration — no Binance system row yet.
            // Resolve to "no accounts" by adding a contradictory clause
            // so the query is syntactically complete but returns empty.
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->where('accounts.is_active', true)
            ->where('accounts.api_system_id', $binanceId)
            ->whereNotNull('accounts.binance_api_key')
            // Pre-fix, the predicate let an account through with a key
            // but no secret — the daemon would then retry-loop the
            // mismatched pair against Binance auth. Require both.
            ->whereNotNull('accounts.binance_api_secret');
    }
}
