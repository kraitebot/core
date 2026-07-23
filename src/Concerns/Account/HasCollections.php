<?php

declare(strict_types=1);

namespace Kraite\Core\Concerns\Account;

use Illuminate\Support\Collection;
use Kraite\Core\Models\ExchangeSymbol;

trait HasCollections
{
    /** @return Collection<int, ExchangeSymbol> */
    public function availableExchangeSymbols(): Collection
    {
        $activeIds = $this->positions()
            ->opened()
            ->pluck('exchange_symbol_id')
            ->filter()
            ->values();

        return ExchangeSymbol::query()
            ->with('apiSystem')
            ->tradeable()
            ->where('exchange_symbols.api_system_id', $this->api_system_id)
            ->where('exchange_symbols.quote', $this->trading_quote)
            ->whereNotIn('exchange_symbols.id', $activeIds)
            ->get()
            ->values();
    }

    /**
     * Returns recently closed positions eligible for fast-track re-entry.
     */
    public function fastTrackedPositions(): Collection
    {
        $config = $this->tradeConfiguration;

        return $this->positions()
            ->where('positions.status', 'closed')
            ->where('positions.closed_at', '>=', now()->subSeconds($config->fast_trade_position_closed_age_seconds))
            ->whereRaw(
                'TIMESTAMPDIFF(SECOND, positions.opened_at, positions.closed_at) <= ?',
                [$config->fast_trade_position_duration_seconds]
            )
            ->whereRaw('TIMESTAMPDIFF(SECOND, positions.opened_at, positions.closed_at) >= 0')
            ->get();
    }
}
