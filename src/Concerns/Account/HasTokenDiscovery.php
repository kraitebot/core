<?php

declare(strict_types=1);

namespace Kraite\Core\Concerns\Account;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Kraite\Core\Trading\Engine;
use Kraite\Core\Models\ApiSnapshot;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;
use Kraite\Core\Models\TokenMapper;

/*
 * Trait HasTokenDiscovery
 *
 * Purpose:
 * - Assigns the most optimal ExchangeSymbol to each "new" position using BTC bias-based selection.
 * - Uses BTC's current direction and timeframe as the basis for scoring and selecting tokens.
 *
 * BTC Bias Algorithm:
 * When BTC has a direction signal (LONG or SHORT):
 *   1. Get BTC's indicators_timeframe (e.g., "4h")
 *   2. Score tokens using: elasticity × |correlation| on that timeframe
 *   3. Optionally filter by correlation sign based on direction alignment
 *
 * Correlation Sign Rules:
 * - BTC=LONG + Position=LONG   → Want POSITIVE correlation (token rises WITH BTC)
 * - BTC=LONG + Position=SHORT  → Want NEGATIVE correlation (token falls AGAINST BTC)
 * - BTC=SHORT + Position=LONG  → Want NEGATIVE correlation (token rises AGAINST BTC)
 * - BTC=SHORT + Position=SHORT → Want POSITIVE correlation (token falls WITH BTC)
 *
 * Rule: (BTC direction == position direction) → want POSITIVE correlation
 *
 * Priority System:
 *   1. Fast-tracked tokens (recently profitable positions) - skip correlation check
 *   2. Best BTC bias score (elasticity × |correlation|) with correlation sign filtering
 *
 * Fallback Behavior (when BTC has no direction):
 * - If btc_biased_restriction=true: Delete all position slots (STRICT mode)
 * - If btc_biased_restriction=false: Use non-BTC algorithm across all timeframes (RELAXED mode)
 *
 * Usage Requirements:
 * - positions() relationship returning Position models
 * - tradeConfiguration property for timeframes
 * - availableExchangeSymbols() method returning usable ExchangeSymbols
 * - fastTrackedPositions() returning recently profitable positions
 */
trait HasTokenDiscovery
{
    /*
     * Collection of currently eligible ExchangeSymbols.
     * Filtered and updated during assignment process.
     */
    public Collection $availableExchangeSymbols;

    /*
     * Tracking string for assigned tokens (used for logging/reporting).
     */
    public string $tokens = '';

    /*
     * Current position being processed.
     */
    public Position $positionReference;

    public function assignBestTokenToNewPositions()
    {
        /*
         * BTC Bias-Based Token Assignment Algorithm
         *
         * Flow:
         * 1. Load available exchange symbols pool
         * 2. Get BTC ExchangeSymbol (same api_system_id and quote)
         * 3. Check BTC direction:
         *    - HAS direction: Use BTC bias algorithm with BTC's timeframe
         *    - NO direction: Check btc_biased_restriction config
         *      - true: Delete all slots, return (STRICT)
         *      - false: Fallback to non-BTC algorithm (RELAXED)
         * 4. For each position:
         *    a) Priority 1: Fast-tracked symbols (direction check only)
         *    b) Priority 2: BTC bias scoring OR fallback scoring
         *    c) Delete unassigned slots
         *
         * Cross-Account Locking:
         * When have_distinct_position_tokens_on_all_accounts is enabled, the ENTIRE
         * method runs under an atomic lock per user. This ensures:
         * - Symbol loading sees current state (including other accounts' assignments)
         * - No race conditions between parallel account jobs
         */

        // If cross-account exclusion is enabled, wrap entire method in atomic lock
        if ($this->user->have_distinct_position_tokens_on_all_accounts) {
            $lockKey = "user:{$this->user->id}:token_assignment_lock";

            return Cache::lock($lockKey, 60)->block(30, function () {
                return $this->executeTokenAssignment();
            });
        }

        return $this->executeTokenAssignment();
    }

    /**
     * Execute the actual token assignment logic.
     * Separated to allow wrapping in atomic lock when needed.
     */
    public function executeTokenAssignment(): string
    {
        // Reset tokens string for each call
        $this->tokens = '';

        /*
         * Step 1: Load Available Exchange Symbols Pool
         *
         * availableExchangeSymbols() returns symbols that:
         * - Are tradeable (is_active=1, is_tradeable=1, has direction)
         * - Match account's trading_quote (usually USDT)
         * - Are NOT already in opened positions for this account (local DB)
         *
         * We then filter to only include symbols from this account's exchange.
         */
        $this->availableExchangeSymbols = $this->availableExchangeSymbols()
            ->where('api_system_id', $this->api_system_id);

        /*
         * Step 1b: Cross-Exchange Token Restriction (Binance Reference)
         *
         * For non-Binance exchanges, only allow tokens that are ALSO tradeable on Binance.
         * Binance is the reference exchange - if a token isn't tradeable there, we don't
         * trade it on other exchanges either. This ensures cross-exchange consistency.
         */
        $binanceApiSystemId = ApiSystem::where('canonical', 'binance')->value('id');

        if ($this->api_system_id !== $binanceApiSystemId) {
            $binanceTradeableTokens = ExchangeSymbol::query()
                ->tradeable()
                ->where('api_system_id', $binanceApiSystemId)
                ->pluck('token');

            $this->availableExchangeSymbols = $this->availableExchangeSymbols
                ->whereIn('token', $binanceTradeableTokens);
        }

        /*
         * Step 1b: Exclude Tokens Already Open on Exchange
         *
         * Check api_snapshots for both:
         * - 'account-positions': Open positions on exchange
         * - 'account-open-orders': Pending orders on exchange
         *
         * Keys in account-positions are formatted as 'BTCUSDT:LONG'.
         * Orders in account-open-orders have 'symbol' field (e.g., 'BTCUSDT').
         */
        $openTradingPairs = collect();

        // Check open positions
        $openPositionsOnExchange = ApiSnapshot::getFrom($this, 'account-positions') ?? [];
        $positionPairs = collect(array_keys($openPositionsOnExchange))
            ->map(static function (string $key): string {
                // Extract trading pair from 'BTCUSDT:LONG' -> 'BTCUSDT'
                return explode(':', $key)[0];
            });
        $openTradingPairs = $openTradingPairs->merge($positionPairs);

        // Check open orders
        $openOrdersOnExchange = ApiSnapshot::getFrom($this, 'account-open-orders') ?? [];
        $orderPairs = collect($openOrdersOnExchange)
            ->pluck('symbol')
            ->filter();
        $openTradingPairs = $openTradingPairs->merge($orderPairs);

        $openTradingPairs = $openTradingPairs->unique()->values();

        if ($openTradingPairs->isNotEmpty()) {
            $this->availableExchangeSymbols = $this->availableExchangeSymbols
                ->filter(static function (ExchangeSymbol $symbol) use ($openTradingPairs): bool {
                    return ! $openTradingPairs->contains($symbol->parsed_trading_pair);
                });
        }

        /*
         * Step 1c: Cross-Account Token Exclusion (User-Level) - Database Check
         *
         * When user has have_distinct_position_tokens_on_all_accounts=true:
         * - Get tokens from ALL user's active positions (across all accounts)
         * - Expand tokens via TokenMapper to include equivalents (e.g., XBT↔BTC, FLOKI↔1000FLOKI)
         * - Exclude all exchange symbols with those tokens
         *
         * This prevents the same token exposure across multiple accounts.
         */
        if ($this->user->have_distinct_position_tokens_on_all_accounts) {
            $activeTokens = $this->user->positions()
                ->opened()
                ->whereNotNull('exchange_symbol_id')
                ->with('exchangeSymbol')
                ->get()
                ->pluck('exchangeSymbol.token')
                ->filter()
                ->unique();

            if ($activeTokens->isNotEmpty()) {
                $excludedTokens = $this->expandTokensWithMappings($activeTokens);

                $this->availableExchangeSymbols = $this->availableExchangeSymbols
                    ->whereNotIn('token', $excludedTokens->all());
            }
        }

        /*
         * Step 1d: Cross-Account Token Exclusion (User-Level) - Cache Check
         *
         * When user has have_distinct_position_tokens_on_all_accounts=true:
         * - Check cache for tokens reserved by other accounts (race condition protection)
         * - This catches tokens that were just assigned but not yet saved to DB
         * - Expand via TokenMapper and exclude from pool
         *
         * Cache key: user:{user_id}:reserved_tokens
         * TTL: 10 minutes (auto-cleans if job fails)
         */
        if ($this->user->have_distinct_position_tokens_on_all_accounts) {
            $cacheKey = "user:{$this->user->id}:reserved_tokens";
            $cachedReservedTokens = Cache::get($cacheKey, []);

            if (! empty($cachedReservedTokens)) {
                $expandedCachedTokens = $this->expandTokensWithMappings(collect($cachedReservedTokens));

                $this->availableExchangeSymbols = $this->availableExchangeSymbols
                    ->whereNotIn('token', $expandedCachedTokens->all());
            }
        }

        /*
         * Step 2: Filter Pool - Only Complete Symbols
         *
         * Filter symbols that have:
         * - Complete trading metadata (min order requirements, tick_size, etc.)
         * - Complete correlation/elasticity data
         *
         * Min order requirements are exchange-specific:
         * - Binance/Bybit/BitGet: Direct min_notional
         * - KuCoin: kucoin_lot_size * kucoin_multiplier * current_price
         */
        $correlationType = config('kraite.token_discovery.correlation_type', 'rolling');
        $correlationField = 'btc_correlation_'.$correlationType;

        $this->availableExchangeSymbols = $this->availableExchangeSymbols->filter(static function ($symbol) use ($correlationField) {
            return Engine::hasMinOrderRequirements($symbol)
                && filled($symbol->tick_size)
                && filled($symbol->price_precision)
                && filled($symbol->quantity_precision)
                && filled($symbol->btc_elasticity_long)
                && filled($symbol->btc_elasticity_short)
                && filled($symbol->{$correlationField});
        });

        /*
         * Step 3: Get BTC ExchangeSymbol for BTC Bias
         *
         * Find BTC's ExchangeSymbol with:
         * - Same api_system_id (same exchange: Binance/Bybit)
         * - Same quote (same trading pair quote: USDT)
         *
         * BTC provides:
         * - direction: LONG/SHORT/null (market bias signal)
         * - indicators_timeframe: Which timeframe the signal was computed on
         */
        $btcSymbol = Symbol::where('token', config('kraite.correlation.btc_token', 'BTC'))->first();
        $btcExchangeSymbol = null;

        if ($btcSymbol) {
            $btcExchangeSymbol = ExchangeSymbol::query()
                ->where('symbol_id', $btcSymbol->id)
                ->where('api_system_id', $this->api_system_id)
                ->where('quote', $this->trading_quote)
                ->first();
        }

        /*
         * Step 4: Get New Positions Ready for Token Assignment
         *
         * Query positions where:
         * - status = 'new' (freshly created)
         * - direction IS NOT NULL (set by earlier job)
         * - exchange_symbol_id IS NULL (no token assigned yet)
         */
        $newPositions = $this->positions()
            ->where('positions.status', 'new')
            ->whereNotNull('positions.direction')
            ->whereNull('positions.exchange_symbol_id')
            ->get();

        /*
         * Step 5: Check BTC Direction - Determines Algorithm Path
         */
        $btcDirection = $btcExchangeSymbol?->direction;
        $btcTimeframe = $btcExchangeSymbol?->indicators_timeframe;
        $btcBiasedRestriction = config('kraite.token_discovery.btc_biased_restriction', true);

        /*
         * If BTC has NO direction and btc_biased_restriction=true:
         * Delete all position slots and return (STRICT mode)
         */
        if (! $btcDirection && $btcBiasedRestriction) {
            $this->deleteUnassignedPositionSlots();

            return '';
        }

        /*
         * Determine algorithm mode:
         * - useBtcBias=true: Use single timeframe from BTC with correlation sign logic
         * - useBtcBias=false: Use fallback algorithm (all timeframes, no sign filtering)
         */
        $useBtcBias = filled($btcDirection) && filled($btcTimeframe);

        /*
         * Step 6: Initialize Batch Exclusions Tracking
         */
        $batchExclusions = [];

        /*
         * Step 7: Iterate Each Position and Assign Best Token
         *
         * Note: When have_distinct_position_tokens_on_all_accounts is enabled,
         * this entire method runs under an atomic lock (see assignBestTokenToNewPositions).
         */
        $this->assignTokensToPositions($newPositions, $useBtcBias, $btcDirection, $btcTimeframe, $batchExclusions);

        /*
         * Step 8: Delete Unassigned Position Slots
         *
         * Clean up positions that couldn't be assigned a token.
         */
        $this->deleteUnassignedPositionSlots();

        return $this->tokens;
    }

    /**
     * Assign tokens to positions (extracted for lock callback).
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, Position>  $newPositions
     */
    public function assignTokensToPositions(
        $newPositions,
        bool $useBtcBias,
        ?string $btcDirection,
        ?string $btcTimeframe,
        array &$batchExclusions
    ): void {
        foreach ($newPositions as $position) {
            $this->positionReference = $position;
            $direction = $position->direction;
            $bestToken = null;

            /*
             * Priority 1: Fast-Tracked Symbols
             *
             * Fast-tracked positions are those that:
             * - Closed recently (within last hour by default)
             * - Had quick duration (<10 minutes by default)
             * - Were profitable
             *
             * Fast-tracked symbols ONLY verify direction match.
             * They skip correlation/elasticity checks entirely.
             */
            $fastTrackedSymbol = $this->getFastTrackedSymbolForDirection($direction, $batchExclusions);
            $wasFastTracked = ($fastTrackedSymbol !== null);
            if ($fastTrackedSymbol) {
                $bestToken = $fastTrackedSymbol;
            }

            /*
             * Priority 2: BTC Bias Scoring OR Fallback Scoring
             */
            if (! $bestToken) {
                if ($useBtcBias) {
                    /*
                     * BTC Bias Algorithm:
                     * - Use BTC's timeframe only
                     * - Apply correlation sign filtering based on direction alignment
                     * - Score: elasticity × |correlation|
                     */
                    $bestToken = $this->selectBestTokenByBtcBias(
                        $direction,
                        $btcDirection,
                        $btcTimeframe,
                        $batchExclusions
                    );
                } else {
                    /*
                     * Fallback Algorithm (when BTC has no direction):
                     * - Iterate ALL timeframes from TradeConfiguration
                     * - No correlation sign filtering
                     * - Score: elasticity × |correlation| (best across all timeframes)
                     */
                    $bestToken = $this->selectBestTokenFallback($direction, $batchExclusions);
                }
            }

            /*
             * No Token Available - Skip Position
             *
             * Position will be deleted below along with other unassigned slots.
             */
            if (! $bestToken) {
                continue;
            }

            /*
             * Assign Token to Position
             */
            $this->tokens .= $bestToken->parsed_trading_pair.'-'.$bestToken->direction.' ';

            $position->updateSaving([
                'exchange_symbol_id' => $bestToken->id,
                'direction' => $bestToken->direction,
                'parsed_trading_pair' => $bestToken->parsed_trading_pair,
                'was_fast_traded' => $wasFastTracked,
            ]);

            $batchExclusions[] = $bestToken->id;

            /*
             * Add Token to User's Reserved Tokens Cache
             *
             * This prevents other accounts (running in parallel) from selecting
             * the same token before this position is fully saved to DB.
             * TTL: 10 minutes (auto-cleans if job fails or position closes)
             */
            if ($this->user->have_distinct_position_tokens_on_all_accounts) {
                $cacheKey = "user:{$this->user->id}:reserved_tokens";
                $reservedTokens = Cache::get($cacheKey, []);
                $reservedTokens[] = $bestToken->token;
                Cache::put($cacheKey, array_unique($reservedTokens), now()->addMinutes(10));
            }
        }
    }

    public function getFastTrackedSymbolForDirection(string $direction, array $batchExclusions)
    {
        /*
         * Fast-Track Symbol Selection
         *
         * Purpose:
         * Prioritize tokens from recently profitable quick trades.
         * Assumes tokens that closed profitably in <10 minutes still have momentum.
         *
         * IMPORTANT: Fast-tracked symbols ONLY check:
         * 1. Direction match (verify it hasn't changed since the fast trade)
         * 2. Not in batch exclusions
         * 3. Available in pool
         *
         * They SKIP correlation/elasticity checks entirely.
         * This is intentional - proven momentum trumps theoretical scoring.
         */

        $fastTracked = $this->fastTrackedPositions()->where('direction', $direction);

        if ($fastTracked->isNotEmpty()) {
            foreach ($fastTracked as $trackedPosition) {
                if (in_array($trackedPosition->exchange_symbol_id, $batchExclusions)) {
                    continue;
                }

                /*
                 * Check if Symbol Still Available AND Direction Matches
                 *
                 * Direction check is critical - the symbol's direction may have
                 * changed since the fast trade. We only want symbols that
                 * STILL have the same direction signal.
                 */
                $symbol = $this->availableExchangeSymbols
                    ->where('direction', $direction)
                    ->whereNotIn('id', $batchExclusions)
                    ->first(static function ($availableSymbol) use ($trackedPosition) {
                        return $availableSymbol->id === $trackedPosition->exchange_symbol_id;
                    });

                if ($symbol) {
                    return $symbol;
                }
            }
        }

        return null;
    }

    public function selectBestTokenByBtcBias(
        string $positionDirection,
        string $btcDirection,
        string $btcTimeframe,
        array $batchExclusions
    ) {
        /*
         * ═══════════════════════════════════════════════════════════════════════════════
         * BTC BIAS-BASED TOKEN SELECTION ALGORITHM
         * ═══════════════════════════════════════════════════════════════════════════════
         *
         * Purpose:
         * Select the optimal trading token based on BTC's current direction.
         * Uses the SYMBOL'S OWN timeframe for correlation/elasticity lookups.
         * Uses correlation sign alignment to maximize position profitability.
         *
         * Note: BTC's timeframe ($btcTimeframe) is used to calculate correlations
         * (same candle data source), but each symbol uses its OWN indicators_timeframe
         * for the lookup key.
         *
         * ─────────────────────────────────────────────────────────────────────────────────
         * CORRELATION SIGN RULES
         * ─────────────────────────────────────────────────────────────────────────────────
         *
         * | BTC Direction | Position Direction | Desired Correlation |
         * |---------------|-------------------|---------------------|
         * | LONG          | LONG              | POSITIVE (token rises WITH BTC) |
         * | LONG          | SHORT             | NEGATIVE (token falls AGAINST BTC) |
         * | SHORT         | LONG              | NEGATIVE (token rises AGAINST BTC) |
         * | SHORT         | SHORT             | POSITIVE (token falls WITH BTC) |
         *
         * Rule: (BTC direction == position direction) → want POSITIVE correlation
         *
         * ─────────────────────────────────────────────────────────────────────────────────
         * SCORING FORMULA (Symbol's Own Timeframe)
         * ─────────────────────────────────────────────────────────────────────────────────
         *
         * For LONG positions:  score = elasticity_long[symbol_timeframe] × |correlation[symbol_timeframe]|
         * For SHORT positions: score = |elasticity_short[symbol_timeframe]| × |correlation[symbol_timeframe]|
         *
         * ─────────────────────────────────────────────────────────────────────────────────
         */

        $correlationType = config('kraite.token_discovery.correlation_type', 'rolling');
        $correlationField = 'btc_correlation_'.$correlationType;
        $requireMatchingSign = config('kraite.token_discovery.require_matching_correlation_sign', true);

        /*
         * Determine desired correlation sign:
         * - Same direction (BTC=LONG, Position=LONG): want POSITIVE
         * - Opposite direction (BTC=LONG, Position=SHORT): want NEGATIVE
         */
        $wantPositiveCorrelation = ($btcDirection === $positionDirection);

        /*
         * Filter Candidate Symbols
         */
        $candidates = $this->availableExchangeSymbols
            ->where('direction', $positionDirection)
            ->whereNotIn('id', $batchExclusions);

        if ($candidates->isEmpty()) {
            return null;
        }

        /*
         * Score Each Candidate Using SYMBOL'S OWN Timeframe
         */
        $scoredSymbols = $candidates->map(static function ($symbol) use (
            $positionDirection,
            $correlationField,
            $requireMatchingSign,
            $wantPositiveCorrelation
        ) {
            /*
             * Use the symbol's own concluded timeframe for lookups
             */
            $symbolTimeframe = $symbol->indicators_timeframe;

            if (! $symbolTimeframe) {
                return null;
            }

            /*
             * Validate Data Availability for Symbol's Timeframe
             */
            if (! isset($symbol->btc_elasticity_long[$symbolTimeframe])
                || ! isset($symbol->btc_elasticity_short[$symbolTimeframe])
                || ! isset($symbol->{$correlationField}[$symbolTimeframe])) {
                return null;
            }

            $elasticityLong = $symbol->btc_elasticity_long[$symbolTimeframe];
            $elasticityShort = $symbol->btc_elasticity_short[$symbolTimeframe];
            $correlation = $symbol->{$correlationField}[$symbolTimeframe];

            /*
             * Apply Correlation Sign Filter (if enabled)
             *
             * If require_matching_correlation_sign=true:
             * - Skip symbols with wrong correlation sign
             * - This ensures high conviction trades
             *
             * If require_matching_correlation_sign=false:
             * - Don't filter by sign
             * - Always find a token if any available
             */
            if ($requireMatchingSign) {
                $hasCorrectSign = $wantPositiveCorrelation
                    ? ($correlation > 0)
                    : ($correlation < 0);

                if (! $hasCorrectSign) {
                    return null;
                }
            }

            /*
             * Calculate Score Using Direction-Specific Formula
             */
            if ($positionDirection === 'SHORT') {
                $score = abs($elasticityShort) * abs($correlation);
            } else {
                $score = $elasticityLong * abs($correlation);
            }

            return [
                'symbol' => $symbol,
                'score' => $score,
                'timeframe' => $symbolTimeframe,
                'correlation' => $correlation,
            ];
        })->filter();

        if ($scoredSymbols->isEmpty()) {
            return null;
        }

        /*
         * Sort by Score and Return Best Symbol
         */
        $best = $scoredSymbols->sortByDesc('score')->first();

        return $best ? $best['symbol'] : null;
    }

    public function selectBestTokenFallback(string $direction, array $batchExclusions)
    {
        /*
         * ═══════════════════════════════════════════════════════════════════════════════
         * FALLBACK TOKEN SELECTION ALGORITHM (No BTC Direction)
         * ═══════════════════════════════════════════════════════════════════════════════
         *
         * Purpose:
         * Select optimal token when BTC has no direction signal.
         * Uses all timeframes and ignores correlation sign (no BTC bias to align with).
         *
         * Scoring Formula:
         * For LONG:  score = elasticity_long × |correlation| (best across all timeframes)
         * For SHORT: score = |elasticity_short| × |correlation| (best across all timeframes)
         *
         * This is the "RELAXED" mode when btc_biased_restriction=false.
         */

        $correlationType = config('kraite.token_discovery.correlation_type', 'rolling');
        $correlationField = 'btc_correlation_'.$correlationType;

        $candidates = $this->availableExchangeSymbols
            ->where('direction', $direction)
            ->whereNotIn('id', $batchExclusions);

        if ($candidates->isEmpty()) {
            return null;
        }

        /*
         * Score Each Candidate Across ALL Timeframes
         */
        $scoredSymbols = $candidates->map(function ($symbol) use ($direction, $correlationField) {
            $timeframes = $symbol->apiSystem->timeframes ?? [];

            $bestScore = 0;
            $bestTimeframe = null;

            foreach ($timeframes as $timeframe) {
                if (! isset($symbol->btc_elasticity_long[$timeframe])
                    || ! isset($symbol->btc_elasticity_short[$timeframe])
                    || ! isset($symbol->{$correlationField}[$timeframe])) {
                    continue;
                }

                $elasticityLong = $symbol->btc_elasticity_long[$timeframe];
                $elasticityShort = $symbol->btc_elasticity_short[$timeframe];
                $correlation = $symbol->{$correlationField}[$timeframe];

                if ($direction === 'SHORT') {
                    $score = abs($elasticityShort) * abs($correlation);
                } else {
                    $score = $elasticityLong * abs($correlation);
                }

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestTimeframe = $timeframe;
                }
            }

            return [
                'symbol' => $symbol,
                'score' => $bestScore,
                'timeframe' => $bestTimeframe,
            ];
        });

        $best = $scoredSymbols->sortByDesc('score')->first();

        return $best ? $best['symbol'] : null;
    }

    public function deleteUnassignedPositionSlots(): int
    {
        /*
         * Delete Unassigned Position Slots
         *
         * Removes positions that:
         * - status = 'new'
         * - exchange_symbol_id IS NULL (no token was assigned)
         *
         * This happens when:
         * - BTC has no direction and btc_biased_restriction=true
         * - No tokens match the correlation sign requirement
         * - All available tokens were already assigned in this batch
         *
         * Returns: Number of deleted positions
         */
        $unassignedPositions = $this->positions()
            ->where('positions.status', 'new')
            ->whereNull('positions.exchange_symbol_id')
            ->get();

        $deletedCount = 0;

        foreach ($unassignedPositions as $position) {
            $position->forceDelete();
            $deletedCount++;
        }

        return $deletedCount;
    }

    /**
     * Expand a collection of tokens to include all equivalent tokens via TokenMapper.
     *
     * For each token:
     * - If it matches a binance_token, add all corresponding other_token values
     * - If it matches an other_token, add the corresponding binance_token
     *
     * Example: FLOKI → [FLOKI, 1000FLOKI], XBT → [XBT, BTC]
     *
     * @param  Collection<int, string>  $tokens
     * @return Collection<int, string>
     */
    public function expandTokensWithMappings(Collection $tokens): Collection
    {
        $expandedTokens = $tokens->values();

        // Find mappings where our tokens are the binance_token
        $fromBinance = TokenMapper::whereIn('binance_token', $tokens)
            ->pluck('other_token');

        // Find mappings where our tokens are the other_token
        $fromOther = TokenMapper::whereIn('other_token', $tokens)
            ->pluck('binance_token');

        return $expandedTokens
            ->merge($fromBinance)
            ->merge($fromOther)
            ->unique()
            ->values();
    }
}
