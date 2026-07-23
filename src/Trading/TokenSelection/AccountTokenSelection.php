<?php

declare(strict_types=1);

namespace Kraite\Core\Trading\TokenSelection;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Kraite as KraiteSettings;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;
use Kraite\Core\Support\SupportResistanceProximity;
use Kraite\Core\Support\TokenScoring\BatchDiversificationPenalty;
use Kraite\Core\Support\TokenScoring\CorrelationStabilityWeight;
use Kraite\Core\Support\TokenScoring\LogElasticityScorer;

/*
 * Account-scoped token selection workflow
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
 * - fastTrackedPositions() returning recently closed positions
 */
final class AccountTokenSelection
{
    /*
     * Collection of currently eligible ExchangeSymbols.
     * Filtered and updated during assignment process.
     */
    private Collection $availableExchangeSymbols;

    /*
     * Tracking string for assigned tokens (used for logging/reporting).
     */
    private string $tokens = '';

    private int $deletedCount = 0;

    /*
     * Current position being processed.
     */
    private Position $positionReference;

    public function __construct(
        private readonly Account $account,
        private readonly TokenCandidatePoolBuilder $candidatePoolBuilder,
    ) {}

    public function assign(): string
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
        if ($this->account->user->have_distinct_position_tokens_on_all_accounts) {
            $lockKey = "user:{$this->account->user->id}:token_assignment_lock";

            return Cache::lock($lockKey, 60)->block(30, function () {
                return $this->executeTokenAssignment();
            });
        }

        return $this->executeTokenAssignment();
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
        $unassignedPositions = $this->account->positions()
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

    public function deletedCount(): int
    {
        return $this->deletedCount;
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
        return $this->candidatePoolBuilder->expandTokensWithMappings($tokens);
    }

    /**
     * Execute the actual token assignment logic.
     * Separated to allow wrapping in atomic lock when needed.
     */
    private function executeTokenAssignment(): string
    {
        // Reset tokens string for each call
        $this->tokens = '';
        $this->deletedCount = 0;

        $this->availableExchangeSymbols = $this->candidatePoolBuilder->build($this->account);

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
                ->where('api_system_id', $this->account->api_system_id)
                ->where('quote', $this->account->trading_quote)
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
        $newPositions = $this->account->positions()
            ->where('positions.status', 'new')
            ->whereNotNull('positions.direction')
            ->whereNull('positions.exchange_symbol_id')
            ->get();

        /*
         * Step 5: Check BTC Direction - Determines Algorithm Path
         */
        $btcDirection = $btcExchangeSymbol?->direction;
        $btcTimeframe = $btcExchangeSymbol?->indicators_timeframe;
        $btcBiasedRestriction = $this->account->usesBtcBiasRestriction();

        /*
         * If BTC has NO direction and btc_biased_restriction=true:
         * Delete all position slots and return (STRICT mode)
         */
        if (! $btcDirection && $btcBiasedRestriction) {
            $this->deletedCount = $this->deleteUnassignedPositionSlots();

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
        $this->deletedCount = $this->deleteUnassignedPositionSlots();

        return $this->tokens;
    }

    /**
     * Assign tokens to positions (extracted for lock callback).
     *
     * @param  EloquentCollection<int, Position>  $newPositions
     * @param  list<int>  $batchExclusions
     */
    private function assignTokensToPositions(
        EloquentCollection $newPositions,
        bool $useBtcBias,
        ?string $btcDirection,
        ?string $btcTimeframe,
        array &$batchExclusions
    ): void {
        // Track 1d BTC correlation of every symbol picked in THIS batch
        // so subsequent picks downscore candidates that look like the
        // same trade we already booked. Diversification penalty uses
        // this to force structural variety across a 6-LONG / 6-SHORT
        // book — see BatchDiversificationPenalty.
        $batchPicks1dCorrelation = [];

        foreach ($newPositions as $position) {
            $this->positionReference = $position;
            $direction = $position->direction;
            $bestToken = null;

            /*
             * Priority 0: Test-only god-mode override.
             *
             * config('kraite.position_creation.symbol_override') can pin
             * a specific symbol onto a specific account, bypassing
             * scoring/eligibility gates entirely. Falls back silently
             * to the regular pipeline below when not configured, when
             * configured for a different account, when the symbol can
             * not be resolved on this exchange, when the symbol is
             * already in an active position, or when the symbol's
             * direction does not match this slot's direction.
             *
             * Documented in config/kraite.php; intended for rehearsing
             * WAP / close / drift flows on a known token.
             */
            $overrideSymbol = $this->resolveSymbolOverrideForSlot($position);
            $wasFastTracked = false;
            if ($overrideSymbol !== null) {
                $bestToken = $overrideSymbol;
            }

            /*
             * Priority 1: Fast-Tracked Symbols
             *
             * Fast-tracked positions are those that:
             * - Closed recently (within last hour by default)
             * - Had quick duration (<10 minutes by default)
             *
             * Fast-tracked symbols ONLY verify direction match.
             * They skip correlation/elasticity checks entirely.
             */
            if (! $bestToken) {
                $fastTrackedSymbol = $this->getFastTrackedSymbolForDirection($direction, $batchExclusions);
                $wasFastTracked = ($fastTrackedSymbol !== null);
                if ($fastTrackedSymbol) {
                    $bestToken = $fastTrackedSymbol;
                }
            }

            /*
             * Priority 2: BTC Bias Scoring OR Fallback Scoring
             */
            if (! $bestToken) {
                if ($useBtcBias && $btcDirection !== null && $btcTimeframe !== null) {
                    /*
                     * BTC Bias Algorithm:
                     * - Use BTC's timeframe only
                     * - Apply correlation sign filtering based on direction alignment
                     * - Score: log(1 + |elasticity|) × |correlation|
                     *   × stability_weight × diversification_penalty × s/r_multiplier
                     */
                    $bestToken = $this->selectBestTokenByBtcBias(
                        $direction,
                        $btcDirection,
                        $btcTimeframe,
                        $batchExclusions,
                        $batchPicks1dCorrelation,
                    );
                } else {
                    /*
                     * Fallback Algorithm (when BTC has no direction):
                     * - Iterate ALL timeframes from TradeConfiguration
                     * - No correlation sign filtering
                     * - Score: log(1 + |elasticity|) × |correlation|
                     *   × stability_weight × diversification_penalty × s/r_multiplier
                     */
                    $bestToken = $this->selectBestTokenFallback(
                        $direction,
                        $batchExclusions,
                        $batchPicks1dCorrelation,
                    );
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

            // Capture this pick's 1d BTC correlation so the next slot's
            // scoring penalises candidates that look structurally
            // identical (same direction-of-movement against BTC). Tokens
            // missing 1d correlation data contribute no constraint.
            $pick1dCorrelation = $bestToken->btc_correlation_rolling['1d'] ?? null;
            if (is_numeric($pick1dCorrelation)) {
                $batchPicks1dCorrelation[] = (float) $pick1dCorrelation;
            }

            /*
             * Add Token to User's Reserved Tokens Cache
             *
             * This prevents other accounts (running in parallel) from selecting
             * the same token before this position is fully saved to DB.
             * TTL: 10 minutes (auto-cleans if job fails or position closes)
             */
            if ($this->account->user->have_distinct_position_tokens_on_all_accounts) {
                $cacheKey = "user:{$this->account->user->id}:reserved_tokens";
                $reservedTokens = Cache::get($cacheKey, []);
                $reservedTokens[] = $bestToken->token;
                Cache::put($cacheKey, array_unique($reservedTokens), now()->addMinutes(10));
            }
        }
    }

    /** @param list<int> $batchExclusions */
    private function getFastTrackedSymbolForDirection(string $direction, array $batchExclusions): ?ExchangeSymbol
    {
        /*
         * Fast-Track Symbol Selection
         *
         * Purpose:
         * Prioritize tokens from recent, quickly closed positions.
         * Assumes a token that completed quickly may still have momentum.
         *
         * IMPORTANT: Fast-tracked symbols ONLY check:
         * 1. Direction match (verify it hasn't changed since the fast trade)
         * 2. Not in batch exclusions
         * 3. Available in pool
         *
         * They SKIP correlation/elasticity checks entirely.
         * This is intentional - recent momentum trumps theoretical scoring.
         */

        $fastTracked = $this->account->fastTrackedPositions()->where('direction', $direction);

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

    /**
     * @param  list<int>  $batchExclusions
     * @param  list<float>  $batchPicks1dCorrelation
     */
    private function selectBestTokenByBtcBias(
        string $positionDirection,
        string $btcDirection,
        string $btcTimeframe,
        array $batchExclusions,
        array $batchPicks1dCorrelation = [],
    ): ?ExchangeSymbol {
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
         * Base term:    log(1 + |elasticity_<dir>[tf]|) × |correlation[tf]|   (LogElasticityScorer)
         * Multipliers:  S/R proximity × correlation stability × batch diversification
         *
         * ─────────────────────────────────────────────────────────────────────────────────
         */

        $correlationType = KraiteSettings::correlationType();
        $correlationField = 'btc_correlation_'.$correlationType;
        $requireMatchingSign = $this->account->usesCorrelationSignFilter();

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
        // Non-static closure: we need $this available inside so the
        // proximity multiplier helper can be called.
        $scoredSymbols = $candidates->map(function ($symbol) use (
            $positionDirection,
            $correlationField,
            $requireMatchingSign,
            $wantPositiveCorrelation,
            $batchPicks1dCorrelation,
        ) {
            $symbolTimeframe = $symbol->indicators_timeframe;

            if (! $symbolTimeframe) {
                return null;
            }

            if (! isset($symbol->btc_elasticity_long[$symbolTimeframe])
                || ! isset($symbol->btc_elasticity_short[$symbolTimeframe])
                || ! isset($symbol->{$correlationField}[$symbolTimeframe])) {
                return null;
            }

            $elasticity = $positionDirection === 'SHORT'
                ? $symbol->btc_elasticity_short[$symbolTimeframe]
                : $symbol->btc_elasticity_long[$symbolTimeframe];

            $correlation = $symbol->{$correlationField}[$symbolTimeframe];

            /*
             * Hard sign-filter retained as a high-conviction gate. Tokens
             * with the wrong-sign correlation are rejected outright before
             * the score multipliers run.
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
             * Base score: log-compressed elasticity × |correlation|. The
             * log keeps a 50× outlier from drowning a 5× token with
             * stronger correlation. Same for both directions because
             * both terms are absolute-valued by the scorer.
             */
            $score = LogElasticityScorer::score(
                (float) $elasticity,
                (float) $correlation,
            );

            /*
             * S/R proximity multiplier. Deprioritises candidates whose
             * mark_price sits near the wrong-side pivot for the concluded
             * direction. Soft — graceful 1.0 when pivot data is absent.
             */
            $score *= $this->supportResistanceMultiplierFor($symbol, $positionDirection);

            /*
             * Correlation stability multiplier. A symbol whose rolling
             * correlation jitters across the lookback windows gets
             * downweighted vs one with a steady correlation. Graceful
             * 1.0 when the stability column has not been populated yet
             * (newly-concluded symbols, post-migration upgrade window).
             */
            $stability = $symbol->btc_correlation_stability[$symbolTimeframe] ?? null;
            $score *= CorrelationStabilityWeight::for(
                is_numeric($stability) ? (float) $stability : null,
            );

            /*
             * Batch diversification penalty. If any symbol already
             * picked in THIS selection cycle has a 1d BTC correlation
             * within the threshold of this candidate's 1d correlation
             * (and on the same side of zero), the candidate is
             * deprioritised so the resulting book is not 6 essentially-
             * identical bets on BTC up.
             */
            $candidate1d = $symbol->btc_correlation_rolling['1d'] ?? null;
            if (is_numeric($candidate1d)) {
                $score *= BatchDiversificationPenalty::for(
                    (float) $candidate1d,
                    $batchPicks1dCorrelation,
                );
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

    /**
     * @param  list<int>  $batchExclusions
     * @param  list<float>  $batchPicks1dCorrelation
     */
    private function selectBestTokenFallback(
        string $direction,
        array $batchExclusions,
        array $batchPicks1dCorrelation = [],
    ): ?ExchangeSymbol {
        /*
         * ═══════════════════════════════════════════════════════════════════════════════
         * FALLBACK TOKEN SELECTION ALGORITHM (No BTC Direction)
         * ═══════════════════════════════════════════════════════════════════════════════
         *
         * Used when BTC has no direction signal — no sign filter applies.
         * Iterates every configured timeframe per candidate and keeps the
         * best-scoring timeframe. Same multiplier stack as the BTC-bias
         * path: log-compressed elasticity × |correlation| × S/R proximity
         * × correlation stability × batch diversification.
         */

        $correlationType = KraiteSettings::correlationType();
        $correlationField = 'btc_correlation_'.$correlationType;

        $candidates = $this->availableExchangeSymbols
            ->where('direction', $direction)
            ->whereNotIn('id', $batchExclusions);

        if ($candidates->isEmpty()) {
            return null;
        }

        $timeframes = KraiteSettings::timeframes();

        $scoredSymbols = $candidates->map(function ($symbol) use (
            $direction,
            $correlationField,
            $timeframes,
            $batchPicks1dCorrelation,
        ) {

            $bestScore = 0.0;
            $bestTimeframe = null;

            foreach ($timeframes as $timeframe) {
                if (! isset($symbol->btc_elasticity_long[$timeframe])
                    || ! isset($symbol->btc_elasticity_short[$timeframe])
                    || ! isset($symbol->{$correlationField}[$timeframe])) {
                    continue;
                }

                $elasticity = $direction === 'SHORT'
                    ? $symbol->btc_elasticity_short[$timeframe]
                    : $symbol->btc_elasticity_long[$timeframe];

                $correlation = $symbol->{$correlationField}[$timeframe];

                $score = LogElasticityScorer::score(
                    (float) $elasticity,
                    (float) $correlation,
                );

                $stability = $symbol->btc_correlation_stability[$timeframe] ?? null;
                $score *= CorrelationStabilityWeight::for(
                    is_numeric($stability) ? (float) $stability : null,
                );

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestTimeframe = $timeframe;
                }
            }

            $bestScore *= $this->supportResistanceMultiplierFor($symbol, $direction);

            // Batch diversification penalty applied after the best
            // timeframe has been chosen — the candidate's redundancy
            // against earlier picks is independent of which timeframe
            // produced the headline score.
            $candidate1d = $symbol->btc_correlation_rolling['1d'] ?? null;
            if (is_numeric($candidate1d)) {
                $bestScore *= BatchDiversificationPenalty::for(
                    (float) $candidate1d,
                    $batchPicks1dCorrelation,
                );
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

    /**
     * Resolve the test-only god-mode symbol override for this slot.
     *
     * Returns the resolved ExchangeSymbol when ALL of:
     *   - config('kraite.position_creation.symbol_override') is a non-empty array
     *   - its `account_id` matches this account
     *   - its `symbol` resolves to an ExchangeSymbol on this account's
     *     api_system_id whose computed parsed_trading_pair equals the
     *     configured value (per-exchange formatting handled by the
     *     account's data mapper through the existing accessor)
     *   - the resolved symbol's direction matches the slot's direction
     *   - no opened position on this account already references that
     *     ExchangeSymbol (guards against forcing a duplicate)
     *
     * Returns null on any miss — silent fallback by design. The caller
     * (assignTokensToPositions) treats null as "no override" and proceeds
     * down the regular fast-tracked / BTC-bias / fallback pipeline.
     */
    private function resolveSymbolOverrideForSlot(Position $position): ?ExchangeSymbol
    {
        $override = config('kraite.position_creation.symbol_override');

        if (! is_array($override) || $override === []) {
            return null;
        }

        $configuredAccountId = $override['account_id'] ?? null;
        $configuredSymbol = $override['symbol'] ?? null;

        if ((int) $configuredAccountId !== (int) $this->account->id) {
            return null;
        }

        if (! is_string($configuredSymbol) || mb_trim($configuredSymbol) === '') {
            return null;
        }

        $targetPair = mb_trim($configuredSymbol);

        // Resolve via the parsed_trading_pair accessor — that accessor
        // delegates to the per-exchange data mapper's baseWithQuote()
        // method, which gives us the right Binance / Bitget / KuCoin /
        // Bybit format without us having to reimplement it here. Scope
        // to this account's api_system_id and trading_quote so the
        // candidate set stays small (~hundreds of rows max).
        $candidate = ExchangeSymbol::query()
            ->where('api_system_id', $this->account->api_system_id)
            ->where('quote', $this->account->trading_quote)
            ->get()
            ->first(function (ExchangeSymbol $symbol) use ($targetPair): bool {
                return $symbol->parsed_trading_pair === $targetPair;
            });

        if ($candidate === null) {
            return null;
        }

        if ($candidate->direction !== $position->direction) {
            return null;
        }

        $alreadyOpen = $this->account->positions()
            ->opened()
            ->where('exchange_symbol_id', $candidate->id)
            ->exists();

        if ($alreadyOpen) {
            return null;
        }

        return $candidate;
    }

    /**
     * Compute the S/R proximity multiplier for a candidate during selection.
     *
     * Reads the symbol's stored pivot columns (R1/R3/S1/S3) and its live
     * mark_price (refreshed by StreamBinancePricesCommand at ~1Hz), then
     * delegates to SupportResistanceProximity for the pure math. Returns
     * 1.0 when any required input is missing so missing pivot data never
     * unintentionally filters a symbol — the gate is additive and
     * opt-in, not a hard requirement.
     */
    private function supportResistanceMultiplierFor(ExchangeSymbol $symbol, string $direction): float
    {
        $safeZone = (float) config('kraite.token_discovery.sr_safe_zone', 0.20);

        return SupportResistanceProximity::computeMultiplier(
            direction: $direction,
            markPrice: $symbol->mark_price,
            r1: $symbol->pivot_r1,
            r3: $symbol->pivot_r3,
            s1: $symbol->pivot_s1,
            s3: $symbol->pivot_s3,
            safeZone: $safeZone,
        );
    }
}
