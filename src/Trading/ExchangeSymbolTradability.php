<?php

declare(strict_types=1);

namespace Kraite\Core\Trading;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Kraite as KraiteSettings;

final class ExchangeSymbolTradability
{
    /**
     * Apply the complete new-position tradability policy.
     *
     * @param  Builder<ExchangeSymbol>  $query
     * @return Builder<ExchangeSymbol>
     */
    public function apply(Builder $query): Builder
    {
        $correlationColumn = 'btc_correlation_'.KraiteSettings::correlationType();

        $query->whereIn(
            'exchange_symbols.api_system_id',
            ApiSystem::query()->active()->select('id'),
        );

        $this->applySymbolConditions($query, 'exchange_symbols', $correlationColumn);

        return $query->whereExists(function (QueryBuilder $binanceSymbols) use ($correlationColumn): void {
            $binanceSymbols
                ->from('exchange_symbols as binance_es')
                ->join('api_systems', 'api_systems.id', '=', 'binance_es.api_system_id')
                ->where('api_systems.canonical', 'binance')
                ->whereColumn('binance_es.token', 'exchange_symbols.token')
                ->whereColumn('binance_es.quote', 'exchange_symbols.quote');

            $this->applySymbolConditions($binanceSymbols, 'binance_es', $correlationColumn);
        });
    }

    /**
     * Apply the policy used by the Binance backtesting approval queue.
     *
     * Approval sets both `was_backtesting_approved` and
     * `is_manually_enabled` to true. These rows therefore pass every other
     * new-position gate and would become tradeable from that approval alone.
     *
     * @param  Builder<ExchangeSymbol>  $query
     * @return Builder<ExchangeSymbol>
     */
    public function applyAwaitingBacktestingApproval(Builder $query): Builder
    {
        $correlationColumn = 'btc_correlation_'.KraiteSettings::correlationType();

        $query
            ->whereIn(
                'exchange_symbols.api_system_id',
                ApiSystem::query()->active()->canonical('binance')->select('id'),
            )
            ->where('exchange_symbols.was_backtesting_approved', false);

        $this->applySymbolConditions(
            $query,
            'exchange_symbols',
            $correlationColumn,
            requireManualEnablement: false,
            requireBacktestingApproval: false,
        );

        return $query;
    }

    public function allows(ExchangeSymbol $exchangeSymbol): bool
    {
        if (! $exchangeSymbol->exists) {
            return false;
        }

        return $this->apply(ExchangeSymbol::query())
            ->whereKey($exchangeSymbol->getKey())
            ->exists();
    }

    /** @param Builder<ExchangeSymbol>|QueryBuilder $query */
    private function applySymbolConditions(
        Builder|QueryBuilder $query,
        string $table,
        string $correlationColumn,
        bool $requireManualEnablement = true,
        bool $requireBacktestingApproval = true,
    ): void {
        $query
            ->where("{$table}.api_statuses->has_taapi_data", true)
            ->where("{$table}.has_no_indicator_data", false)
            ->where("{$table}.is_marked_for_delisting", false)
            ->whereNull("{$table}.system_disabled_at")
            ->where("{$table}.is_price_aligned", true)
            ->where("{$table}.has_price_trend_misalignment", false)
            ->where("{$table}.has_early_direction_change", false)
            ->where("{$table}.has_invalid_indicator_direction", false)
            ->whereNotNull("{$table}.symbol_id")
            ->whereNotNull("{$table}.leverage_brackets");

        if ($requireManualEnablement) {
            $query->where(static function (Builder|QueryBuilder $manuallyEnabled) use ($table): void {
                $manuallyEnabled
                    ->whereNull("{$table}.is_manually_enabled")
                    ->orWhere("{$table}.is_manually_enabled", true);
            });
        }

        if ($requireBacktestingApproval) {
            $query->where("{$table}.was_backtesting_approved", true);
        }

        $query
            ->whereNotNull("{$table}.direction")
            ->where(static function (Builder|QueryBuilder $cooldown) use ($table): void {
                $cooldown
                    ->whereNull("{$table}.tradeable_at")
                    ->orWhere("{$table}.tradeable_at", '<=', now());
            })
            ->whereNotNull("{$table}.indicators_timeframe")
            ->whereRaw(
                "JSON_EXTRACT({$table}.{$correlationColumn}, CONCAT('$.\"', {$table}.indicators_timeframe, '\"')) IS NOT NULL",
            );
    }
}
