<?php

declare(strict_types=1);

namespace Kraite\Core\Concerns\ExchangeSymbol;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Kraite\Core\Models\Position;
use Kraite\Core\Trading\ExchangeSymbolTradability;

trait HasScopes
{
    public function scopeOnActiveApiSystem(Builder $query): Builder
    {
        return $query->whereHas(
            'apiSystem',
            static fn (Builder $apiSystems): Builder => $apiSystems->active()
        );
    }

    /**
     * Symbols that can be used to open positions.
     * Checks: corresponding Binance symbol is tradeable, linked to CMC symbol, manually enabled,
     * has TAAPI data, has direction, respects cooldowns, no behavioral flags, has correlation
     * data for the symbol's concluded timeframe, and has leverage brackets data.
     */
    public function scopeTradeable(Builder $query): Builder
    {
        return (new ExchangeSymbolTradability)->apply($query);
    }

    /**
     * Binance symbols where approval is the only remaining operator action
     * before the complete new-position tradability policy passes.
     */
    public function scopeAwaitingBacktestingApproval(Builder $query): Builder
    {
        return (new ExchangeSymbolTradability)->applyAwaitingBacktestingApproval($query);
    }

    /**
     * Symbols that should receive price updates via WebSocket.
     */
    public function scopeNeedsPriceUpdates(Builder $query): Builder
    {
        return $query->onActiveApiSystem()->where(static function ($q) {
            // Has indicator data OR we're still trying to get it
            $q->where('exchange_symbols.has_no_indicator_data', false)
            // And not explicitly disabled by admin (NULL or true, but not false)
                ->where(static function ($q2) {
                    $q2->whereNull('exchange_symbols.is_manually_enabled')
                        ->orWhere('exchange_symbols.is_manually_enabled', true);
                });
        });
    }

    /**
     * Symbols that should attempt to fetch indicators.
     * Only Binance symbols can query TAAPI - other exchanges receive copied results.
     */
    public function scopeNeedsIndicatorAttempt(Builder $query): Builder
    {
        return $query
            ->onActiveApiSystem()
            ->where('exchange_symbols.api_statuses->has_taapi_data', true)
            ->whereHas('apiSystem', static function ($q) {
                $q->canonical('binance');
            });
    }

    /**
     * Excludes terminal symbols from a query. Warning-only rows remain
     * operational until a past `delivery_at` confirms removal.
     */
    public function scopeNotDelisted(Builder $query): Builder
    {
        return $query->where(static function ($query): void {
            $query->whereNull('delivery_at')
                ->orWhere('delivery_at', '>', now());
        });
    }

    /**
     * Keep delisted rows out of new-trading work while retaining symbols that
     * still carry positions requiring price and exchange-state monitoring.
     */
    public function scopeNeedsOperationalMonitoring(Builder $query): Builder
    {
        $openedStatuses = (new Position)->openedStatuses();

        return $query->where(static function (Builder $query) use ($openedStatuses): void {
            $query->notDelisted()
                ->orWhereExists(static fn (QueryBuilder $positions) => $positions
                    ->selectRaw('1')
                    ->from('positions')
                    ->whereColumn('positions.exchange_symbol_id', 'exchange_symbols.id')
                    ->whereIn('positions.status', $openedStatuses));
        });
    }
}
