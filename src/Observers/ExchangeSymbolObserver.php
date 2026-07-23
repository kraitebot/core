<?php

declare(strict_types=1);

namespace Kraite\Core\Observers;

use Illuminate\Support\Once;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\ExchangeSymbolPrice;
use Kraite\Core\Models\TokenMapper;
use Kraite\Core\Support\Math;
use Kraite\Core\Support\Proxies\TradingMapperProxy;

final class ExchangeSymbolObserver
{
    /**
     * Fields that affect tradeable status and should be propagated from Binance to other exchanges.
     * When any of these fields change on a Binance symbol, they are synced to overlapping symbols.
     */
    private const TRADEABLE_FIELDS = [
        'direction',
        'indicators_values',
        'indicators_timeframe',
        'indicators_synced_at',
        'has_no_indicator_data',
        'has_price_trend_misalignment',
        'has_early_direction_change',
        'has_invalid_indicator_direction',
        'btc_correlation_pearson',
        'btc_correlation_spearman',
        'btc_correlation_rolling',
        'btc_elasticity_long',
        'btc_elasticity_short',
    ];

    /**
     * Reset the cached Binance system ID.
     * Useful for testing - clears the Once cache that holds the ID.
     */
    public static function resetBinanceSystemIdCache(): void
    {
        Once::flush();
    }

    public function creating(ExchangeSymbol $model): void
    {
        // Default api_statuses on creation
        $apiStatuses = $model->api_statuses ?? [];

        // Default cmc_api_called: true if symbol_id is set, false otherwise
        if (! isset($apiStatuses['cmc_api_called'])) {
            $apiStatuses['cmc_api_called'] = $model->symbol_id !== null;
        }

        // Default taapi_verified to false (not yet checked)
        if (! isset($apiStatuses['taapi_verified'])) {
            $apiStatuses['taapi_verified'] = false;
        }

        // Default has_taapi_data to false (unknown until verified)
        if (! isset($apiStatuses['has_taapi_data'])) {
            $apiStatuses['has_taapi_data'] = false;
        }

        $model->api_statuses = $apiStatuses;
    }

    public function created(ExchangeSymbol $model): void
    {
        ExchangeSymbolPrice::firstOrCreate(
            ['exchange_symbol_id' => $model->id],
        );
    }

    /**
     * Handle overlap logic before saving (create or update).
     * Sets overlaps_with_binance and handles delisting cascades.
     */
    public function saving(ExchangeSymbol $model): void
    {
        $apiSystem = ApiSystem::find($model->api_system_id);

        if ($apiSystem === null) {
            return;
        }

        $isBinance = $apiSystem->canonical === 'binance';

        if ($isBinance) {
            // Rule 1: Binance symbols always overlap with themselves
            $model->overlaps_with_binance = true;

            // Rule 3: If Binance symbol is being delisted, mark other exchanges' overlaps as false
            if ($this->isBeingDelisted($model, $apiSystem->canonical)) {
                $this->setOtherExchangesOverlap($model, false);
            } elseif ((bool) $model->getRawOriginal('is_marked_for_delisting')
                && ! $model->is_marked_for_delisting
                && ! $model->isDelisted()) {
                // A pair that returns restores only automatic overlap state.
                $this->setOtherExchangesOverlap($model, true);
            }
        } else {
            // Rule 2: Non-Binance - overlaps when Binance lists the SAME ASSET.
            // The canonical CMC symbol_id is the naming-agnostic identity
            // (BitGet FLOKI shares symbol_id 72 with Binance 1000FLOKI), so it
            // resolves 1000x / numeric-suffix / alias divergences with no
            // hand-seeded TokenMapper row. Exact-token + TokenMapper matching
            // stays as the fallback for rows CMC has not resolved yet.
            $model->overlaps_with_binance = $this->binanceListsSymbolId($model->symbol_id)
                || $this->tokenExistsOnBinance($model->token, $model->api_system_id);
        }
    }

    public function updating(ExchangeSymbol $model): void
    {
        // If symbol_id is being set, mark CMC as verified
        if ($model->isDirty('symbol_id') && $model->symbol_id !== null) {
            $apiStatuses = $model->api_statuses ?? [];
            if (($apiStatuses['cmc_api_called'] ?? false) !== true) {
                $apiStatuses['cmc_api_called'] = true;
                $model->api_statuses = $apiStatuses;
            }
        }
    }

    public function saved(ExchangeSymbol $model): void
    {
        $model->sendDelistingNotificationIfNeeded();

        // Symmetric propagation: backtesting is a per-token decision, not
        // per-exchange. Both the boolean gate AND the admin-side review
        // state fan out from any source to every sibling exchange row
        // (linkage via `symbol_id`). saveQuietly() skips this observer on
        // the siblings → no recursion fan-out.
        if ($model->wasChanged(['was_backtesting_approved', 'backtesting_review_status'])) {
            $this->propagateBacktestingReviewToSiblings($model);
        }

        if (! $this->isBinanceSymbol($model)) {
            return;
        }

        // Asymmetric propagation: per-symbol TP/SL overrides are pinned
        // by Binance backtesting data, so only Binance edits fan out.
        // Edits on Bitget / KuCoin / Kraken stay local — prevents the
        // Bitget→Binance→others re-propagation deadlock that a symmetric
        // observer would create.
        if ($model->wasChanged(['profit_percentage', 'stop_market_percentage'])) {
            $this->propagateTpSlOverridesToSiblings($model);
        }

        // Same asymmetric Binance→siblings shape for ladder gaps. Backtest
        // tunes the gap on Binance candles; the resulting value applies to
        // every exchange that lists the same token. Non-Binance edits stay
        // local for the same deadlock-avoidance reason as TP/SL.
        if ($model->wasChanged(['percentage_gap_long', 'percentage_gap_short'])) {
            $this->propagateGapsToSiblings($model);
        }

        // Propagate tradeable-related fields if any of them changed
        $fieldsToCheck = array_merge(self::TRADEABLE_FIELDS, ['api_statuses']);

        if ($model->wasChanged($fieldsToCheck)) {
            $this->propagateTradeableFieldsToOverlappingSymbols($model);
        }
    }

    /**
     * Check if a token exists on Binance (direct match or via TokenMapper).
     */
    public function tokenExistsOnBinance(string $token, ?int $apiSystemId = null): bool
    {
        $binanceSystemId = $this->getBinanceSystemId();

        if ($binanceSystemId === null) {
            return false;
        }

        // Direct token match
        $directMatch = ExchangeSymbol::where('api_system_id', $binanceSystemId)
            ->where('token', $token)
            ->exists();

        if ($directMatch) {
            return true;
        }

        // Check via TokenMapper for name variations (e.g., PEPE on KuCoin = 1000PEPE on Binance)
        if ($apiSystemId !== null) {
            $mapping = TokenMapper::where('other_token', $token)
                ->where('other_api_system_id', $apiSystemId)
                ->first();

            if ($mapping !== null) {
                return ExchangeSymbol::where('api_system_id', $binanceSystemId)
                    ->where('token', $mapping->binance_token)
                    ->exists();
            }
        }

        return false;
    }

    /**
     * True when Binance lists a symbol sharing this canonical CMC symbol_id —
     * the naming-agnostic identity match. A null symbol_id never matches: an
     * unresolved row must not bridge to an arbitrary Binance symbol.
     */
    public function binanceListsSymbolId(?int $symbolId): bool
    {
        if ($symbolId === null) {
            return false;
        }

        $binanceSystemId = $this->getBinanceSystemId();

        if ($binanceSystemId === null) {
            return false;
        }

        return ExchangeSymbol::where('api_system_id', $binanceSystemId)
            ->where('symbol_id', $symbolId)
            ->exists();
    }

    /**
     * Propagate Binance availability to same-asset rows without observer loops.
     */
    public function setOtherExchangesOverlap(ExchangeSymbol $model, bool $overlaps): void
    {
        $binanceSystemId = $this->getBinanceSystemId();

        if ($binanceSystemId === null) {
            return;
        }

        ExchangeSymbol::withoutEvents(function () use ($model, $binanceSystemId, $overlaps): void {
            ExchangeSymbol::query()
                ->where('api_system_id', '!=', $binanceSystemId)
                ->where(function ($query) use ($model): void {
                    if ($model->symbol_id !== null) {
                        $query->where('symbol_id', $model->symbol_id);

                        return;
                    }

                    $query->where('token', $model->token);
                })
                ->update(['overlaps_with_binance' => $overlaps]);
        });
    }

    /**
     * Check if a symbol is being delisted for the first time.
     * Only triggers on first detection to prevent repeated cascades.
     */
    public function isBeingDelisted(ExchangeSymbol $model, string $canonical): bool
    {
        // Already marked before this save - no need to cascade again.
        if ((bool) $model->getRawOriginal('is_marked_for_delisting')) {
            return false;
        }

        if ($model->is_marked_for_delisting) {
            return true;
        }

        $proxy = new TradingMapperProxy($canonical);

        if ($proxy->isNowDelisted($model)) {
            // Mark on the model so it gets saved with this state
            $model->is_marked_for_delisting = true;

            return true;
        }

        return false;
    }

    /**
     * Check if a symbol belongs to Binance.
     */
    public function isBinanceSymbol(ExchangeSymbol $model): bool
    {
        $binanceSystemId = $this->getBinanceSystemId();

        return $binanceSystemId !== null && $model->api_system_id === $binanceSystemId;
    }

    /**
     * Get the Binance system ID, caching it for performance.
     * Uses Laravel's once() helper which is properly cleared between tests.
     */
    public function getBinanceSystemId(): ?int
    {
        return once(function (): ?int {
            return ApiSystem::canonical('binance')->value('id');
        });
    }

    /**
     * Sync `was_backtesting_approved` AND `backtesting_review_status` to
     * every sibling exchange-symbol row for the same underlying token.
     * Both fields propagate together so the boolean gate and the admin-
     * side review state never drift apart across exchanges. saveQuietly()
     * on the sibling save skips this observer → no recursion fan-out.
     */
    private function propagateBacktestingReviewToSiblings(ExchangeSymbol $model): void
    {
        $siblings = $model->getOthersFromExchanges();
        $newApproved = (bool) $model->was_backtesting_approved;
        $newStatus = $model->backtesting_review_status;

        foreach ($siblings as $sibling) {
            $dirty = false;

            if ((bool) $sibling->was_backtesting_approved !== $newApproved) {
                $sibling->was_backtesting_approved = $newApproved;
                $dirty = true;
            }

            if ($sibling->backtesting_review_status !== $newStatus) {
                $sibling->backtesting_review_status = $newStatus;
                $dirty = true;
            }

            if ($dirty) {
                $sibling->saveQuietly();
            }
        }
    }

    /**
     * Sync ladder-gap percentages (`percentage_gap_long`, `percentage_gap_short`)
     * from a Binance row down to every sibling exchange row. Caller already
     * gated this on `isBinanceSymbol === true`, so this method assumes
     * Binance is the source — non-Binance rows never reach here, which
     * makes the propagation strictly one-directional.
     *
     * Decimal columns are returned by the driver as strings — `'9.50'` and
     * `'9.5'` are numerically equal but `===` would mismatch them, so we
     * normalise via Math::equal to keep idempotent saves a no-op (no
     * useless DB write, no `updated_at` flap on siblings).
     */
    private function propagateGapsToSiblings(ExchangeSymbol $model): void
    {
        $siblings = $model->getOthersFromExchanges();
        $newLong = $model->percentage_gap_long;
        $newShort = $model->percentage_gap_short;

        foreach ($siblings as $sibling) {
            $dirty = false;

            if (! $this->decimalsEqual($sibling->percentage_gap_long, $newLong)) {
                $sibling->percentage_gap_long = $newLong;
                $dirty = true;
            }

            if (! $this->decimalsEqual($sibling->percentage_gap_short, $newShort)) {
                $sibling->percentage_gap_short = $newShort;
                $dirty = true;
            }

            if ($dirty) {
                $sibling->saveQuietly();
            }
        }
    }

    /**
     * Sync per-symbol TP/SL overrides from a Binance row down to every
     * sibling exchange row (same underlying token). Caller already gated
     * this on `isBinanceSymbol === true`, so this method assumes Binance
     * is the source. saveQuietly() bypasses observer re-firing on the
     * siblings, keeping propagation strictly one-directional.
     *
     * Decimal columns are returned by the driver as strings — comparing
     * raw via `===` would mismatch `'0.500'` vs `'0.50'` even though
     * numerically equal. We normalise both sides through Math::equal so
     * an idempotent re-save doesn't trigger a useless DB write.
     */
    private function propagateTpSlOverridesToSiblings(ExchangeSymbol $model): void
    {
        $siblings = $model->getOthersFromExchanges();
        $newProfit = $model->profit_percentage;
        $newStop = $model->stop_market_percentage;

        foreach ($siblings as $sibling) {
            $dirty = false;

            if (! $this->decimalsEqual($sibling->profit_percentage, $newProfit)) {
                $sibling->profit_percentage = $newProfit;
                $dirty = true;
            }

            if (! $this->decimalsEqual($sibling->stop_market_percentage, $newStop)) {
                $sibling->stop_market_percentage = $newStop;
                $dirty = true;
            }

            if ($dirty) {
                $sibling->saveQuietly();
            }
        }
    }

    /**
     * Compare two nullable decimal values safely across the string/int/float
     * spectrum. Eloquent returns decimal columns as strings by default, but
     * callers (e.g. admin controllers) may cast to float before assignment;
     * accepting all three keeps the observer crash-free regardless of the
     * write path. NULL on either side counts as a state change; otherwise
     * we delegate to Math::equal for precision-safe equality (avoids the
     * '0.500' vs '0.50' false-mismatch and the 9 vs '9.50' float-vs-decimal
     * false-mismatch).
     */
    private function decimalsEqual(string|int|float|null $a, string|int|float|null $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        return Math::equal($a, $b);
    }

    /**
     * Propagate tradeable-related fields from a Binance symbol to overlapping non-Binance symbols.
     * Uses withoutEvents to prevent circular observer calls.
     */
    private function propagateTradeableFieldsToOverlappingSymbols(ExchangeSymbol $binanceSymbol): void
    {
        $binanceSystemId = $this->getBinanceSystemId();

        if ($binanceSystemId === null) {
            return;
        }

        // Build update data from current Binance symbol values
        $updateData = [];
        foreach (self::TRADEABLE_FIELDS as $field) {
            $updateData[$field] = $binanceSymbol->{$field};
        }

        // Also sync has_taapi_data from api_statuses
        $hasTaapiData = $binanceSymbol->api_statuses['has_taapi_data'] ?? false;

        // Direct token match propagation
        ExchangeSymbol::withoutEvents(function () use ($binanceSymbol, $updateData, $hasTaapiData, $binanceSystemId): void {
            ExchangeSymbol::where('token', $binanceSymbol->token)
                ->where('api_system_id', '!=', $binanceSystemId)
                ->where('overlaps_with_binance', true)
                ->each(function (ExchangeSymbol $symbol) use ($updateData, $hasTaapiData): void {
                    $apiStatuses = $symbol->api_statuses ?? [];
                    $apiStatuses['has_taapi_data'] = $hasTaapiData;
                    $updateData['api_statuses'] = $apiStatuses;
                    $symbol->updateSaving($updateData);
                });
        });

        // TokenMapper propagation for different token names across exchanges
        $this->propagateTradeableFieldsViaMappedTokens($binanceSymbol, $updateData, $hasTaapiData, $binanceSystemId);
    }

    /**
     * Propagate tradeable fields via TokenMapper for cross-exchange token name differences.
     * E.g., 1000SHIB on Binance = SHIB on KuCoin.
     *
     * @param  array<string, mixed>  $updateData
     */
    private function propagateTradeableFieldsViaMappedTokens(
        ExchangeSymbol $binanceSymbol,
        array $updateData,
        bool $hasTaapiData,
        int $binanceSystemId
    ): void {
        // Find mappings where this Binance token has different names on other exchanges
        $mappings = TokenMapper::where('binance_token', $binanceSymbol->token)->get();

        if ($mappings->isEmpty()) {
            return;
        }

        ExchangeSymbol::withoutEvents(function () use ($mappings, $updateData, $hasTaapiData): void {
            foreach ($mappings as $mapping) {
                ExchangeSymbol::where('api_system_id', $mapping->other_api_system_id)
                    ->where('token', $mapping->other_token)
                    ->where('overlaps_with_binance', true)
                    ->each(function (ExchangeSymbol $symbol) use ($updateData, $hasTaapiData): void {
                        $apiStatuses = $symbol->api_statuses ?? [];
                        $apiStatuses['has_taapi_data'] = $hasTaapiData;
                        $updateData['api_statuses'] = $apiStatuses;
                        $symbol->updateSaving($updateData);
                    });
            }
        });
    }
}
