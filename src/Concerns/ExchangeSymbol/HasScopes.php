<?php

declare(strict_types=1);

namespace Kraite\Core\Concerns\ExchangeSymbol;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

trait HasScopes
{
    /**
     * Symbols that can be used to open positions.
     * Checks: corresponding Binance symbol is tradeable, linked to CMC symbol, manually enabled,
     * has TAAPI data, has direction, respects cooldowns, no behavioral flags, has correlation
     * data for the symbol's concluded timeframe, and has leverage brackets data.
     */
    public function scopeTradeable(Builder $query): Builder
    {
        $correlationType = config('kraite.token_discovery.correlation_type', 'rolling');
        $correlationColumn = 'btc_correlation_'.$correlationType;

        // Apply tradeable conditions to the main symbol
        $this->applyTradeableConditions($query, 'exchange_symbols', $correlationColumn);

        // Ensure a tradeable Binance counterpart exists (or this IS a Binance symbol)
        return $query->whereExists(function (QueryBuilder $subquery) use ($correlationColumn) {
            $subquery->from('exchange_symbols as binance_es')
                ->join('api_systems', 'api_systems.id', '=', 'binance_es.api_system_id')
                ->where('api_systems.canonical', 'binance')
                ->whereColumn('binance_es.token', 'exchange_symbols.token')
                ->whereColumn('binance_es.quote', 'exchange_symbols.quote');

            $this->applyTradeableConditionsToQuery($subquery, 'binance_es', $correlationColumn);
        });
    }

    /**
     * Symbols that should receive price updates via WebSocket.
     */
    public function scopeNeedsPriceUpdates(Builder $query): Builder
    {
        return $query->where(static function ($q) {
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
            ->where('exchange_symbols.api_statuses->has_taapi_data', true)
            ->whereHas('apiSystem', static function ($q) {
                $q->where('canonical', 'binance');
            });
    }

    /**
     * Apply tradeable conditions to an Eloquent Builder.
     */
    private function applyTradeableConditions(Builder $query, string $table, string $correlationColumn): void
    {
        $query->where("{$table}.api_statuses->has_taapi_data", true)
            ->where("{$table}.has_no_indicator_data", false)
            ->where("{$table}.is_marked_for_delisting", false)
            ->where("{$table}.has_price_trend_misalignment", false)
            ->where("{$table}.has_early_direction_change", false)
            ->where("{$table}.has_invalid_indicator_direction", false)
            ->whereNotNull("{$table}.symbol_id")
            ->whereNotNull("{$table}.leverage_brackets")
            ->where(static function ($q) use ($table) {
                $q->whereNull("{$table}.is_manually_enabled")
                    ->orWhere("{$table}.is_manually_enabled", true);
            })
            ->where("{$table}.was_backtesting_approved", true)
            ->whereNotNull("{$table}.direction")
            ->where(static function ($q) use ($table) {
                $q->whereNull("{$table}.tradeable_at")
                    ->orWhere("{$table}.tradeable_at", '<=', now());
            })
            ->whereNotNull("{$table}.indicators_timeframe")
            ->whereRaw(
                "JSON_EXTRACT({$table}.{$correlationColumn}, CONCAT('$.\"', {$table}.indicators_timeframe, '\"')) IS NOT NULL"
            );
    }

    /**
     * Apply tradeable conditions to a Query Builder (for subqueries).
     */
    private function applyTradeableConditionsToQuery(QueryBuilder $query, string $table, string $correlationColumn): void
    {
        $query->where("{$table}.api_statuses->has_taapi_data", true)
            ->where("{$table}.has_no_indicator_data", false)
            ->where("{$table}.is_marked_for_delisting", false)
            ->where("{$table}.has_price_trend_misalignment", false)
            ->where("{$table}.has_early_direction_change", false)
            ->where("{$table}.has_invalid_indicator_direction", false)
            ->whereNotNull("{$table}.symbol_id")
            ->whereNotNull("{$table}.leverage_brackets")
            ->where(static function ($q) use ($table) {
                $q->whereNull("{$table}.is_manually_enabled")
                    ->orWhere("{$table}.is_manually_enabled", true);
            })
            ->where("{$table}.was_backtesting_approved", true)
            ->whereNotNull("{$table}.direction")
            ->where(static function ($q) use ($table) {
                $q->whereNull("{$table}.tradeable_at")
                    ->orWhere("{$table}.tradeable_at", '<=', now());
            })
            ->whereNotNull("{$table}.indicators_timeframe")
            ->whereRaw(
                "JSON_EXTRACT({$table}.{$correlationColumn}, CONCAT('$.\"', {$table}.indicators_timeframe, '\"')) IS NOT NULL"
            );
    }
}
