<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\Position;

use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\MarketRegime\BlackSwanIndex;
use Kraite\Core\Support\MarketRegime\CrowdingMultiplier;
use Kraite\Core\Support\MarketRegime\FragileMarginMultiplier;
use Kraite\Core\Support\Math;
use Kraite\Core\Support\TpSlResolver;

/**
 * PreparePositionDataJob (Atomic)
 *
 * Populates position data before market order placement.
 * Sets margin (with subscription cap), profit percentage,
 * indicators, and total limit orders.
 *
 * NOTE: Leverage is NOT set here. It's determined by DetermineLeverageJob
 * which runs after this job and calculates optimal leverage based on
 * the margin and exchange symbol's leverage brackets.
 *
 * Must run AFTER token assignment (exchange_symbol_id must be set).
 * Must run BEFORE DetermineLeverageJob and PlaceMarketOrderJob.
 */
final class PreparePositionDataJob extends BaseQueueableJob
{
    public Position $position;

    public bool $hasValidMargin = false;

    public function __construct(int $positionId)
    {
        $this->position = Position::findOrFail($positionId);
    }

    public function relatable()
    {
        return $this->position;
    }

    /**
     * Position must have exchange_symbol_id assigned by token assignment step.
     */
    public function startOrFail(): bool
    {
        return $this->position->exchange_symbol_id !== null;
    }

    public function compute()
    {
        $account = $this->position->account;
        $exchangeSymbol = $this->position->exchangeSymbol;
        $direction = $this->position->direction;

        // Direction is load-bearing here — LONG and SHORT read different
        // margin columns so the desk can size the two sides asymmetrically.
        $baseMargin = $this->calculateMarginWithSubscriptionCap($account, $direction);

        // BSCS Phase 2.1C — multiplicative size adaptation.
        //   - FragileMarginMultiplier: linear 1.0→0.5 across BSCS 60-79.
        //   - CrowdingMultiplier:      downscales the side that already
        //                              carries >= 70% of the book's notional
        //                              risk (locked: empty side stays 1.0×).
        // Both default to 1.0× outside their trigger windows, so the
        // composition is a no-op in calm regimes / balanced books.
        $index = BlackSwanIndex::current();
        $fragileMultiplier = FragileMarginMultiplier::for($index->score());
        $crowdingMultiplier = CrowdingMultiplier::for($direction, $index->portfolioRisk());
        $sizingMultiplier = $fragileMultiplier * $crowdingMultiplier;

        $margin = $sizingMultiplier === 1.0
            ? $baseMargin
            : Math::mul($baseMargin, (string) $sizingMultiplier);

        $this->hasValidMargin = Math::gt($margin, '0', 2);

        if (! $this->hasValidMargin) {
            return [
                'position_id' => $this->position->id,
                'stopped' => true,
                'reason' => 'Margin is zero or negative - no balance available',
                'calculated_margin' => $margin,
                'base_margin' => $baseMargin,
                'fragile_multiplier' => $fragileMultiplier,
                'crowding_multiplier' => $crowdingMultiplier,
            ];
        }

        // TP and SL are resolved through the per-symbol overrides chain:
        // symbol-NULL falls back to account, account override forces account,
        // otherwise symbol value wins. Snapshot both onto the position so
        // mid-life edits to either side never retroactively rewrite an
        // open position's exit policy.
        $profitPercentage = TpSlResolver::resolve(
            symbolValue: $exchangeSymbol->profit_percentage,
            accountOverride: (bool) $account->override_tp,
            accountValue: (string) $account->profit_percentage,
        );

        $stopMarketPercentage = TpSlResolver::resolve(
            symbolValue: $exchangeSymbol->stop_market_percentage,
            accountOverride: (bool) $account->override_sl,
            accountValue: (string) $account->stop_market_initial_percentage,
        );

        $indicatorsValues = $exchangeSymbol->indicators_values;
        $indicatorsTimeframe = $exchangeSymbol->indicators_timeframe;
        $totalLimitOrders = $exchangeSymbol->total_limit_orders ?? 4;

        // Leverage is set later by DetermineLeverageJob based on the
        // resolved margin + leverage brackets.
        $this->position->updateSaving([
            'margin' => $margin,
            'profit_percentage' => $profitPercentage,
            'stop_market_percentage' => $stopMarketPercentage,
            'indicators_values' => $indicatorsValues,
            'indicators_timeframe' => $indicatorsTimeframe,
            'total_limit_orders' => $totalLimitOrders,
        ]);

        $this->position->updateToOpening();

        return [
            'position_id' => $this->position->id,
            'exchange_symbol' => $exchangeSymbol->parsed_trading_pair,
            'direction' => $direction,
            'margin' => $margin,
            'base_margin' => $baseMargin,
            'fragile_multiplier' => $fragileMultiplier,
            'crowding_multiplier' => $crowdingMultiplier,
            'profit_percentage' => $profitPercentage,
            'stop_market_percentage' => $stopMarketPercentage,
            'indicators_timeframe' => $indicatorsTimeframe,
            'total_limit_orders' => $totalLimitOrders,
            'was_fast_traded' => $this->position->was_fast_traded,
        ];
    }

    /**
     * Calculate margin with subscription cap, direction-aware.
     *
     * Formula: balance × (margin_percentage_<direction> / 100)
     * Cap: subscription.max_balance (if not unlimited)
     *
     * @param  'LONG'|'SHORT'  $direction
     */
    public function calculateMarginWithSubscriptionCap(Account $account, string $direction): string
    {
        // Balance was stored by VerifyMinAccountBalanceJob earlier in the chain.
        $balance = $account->balanceForTrading();

        // Direction-aware percent — LONG and SHORT sizing are tuned
        // independently on the account. Fallback 5.00 matches the
        // default we ship on both columns at migration time.
        $pct = $direction === 'SHORT'
            ? ($account->margin_percentage_short ?? '5.00')
            : ($account->margin_percentage_long ?? '5.00');

        $margin = Math::mul($balance, Math::div((string) $pct, '100'));

        // Check subscription cap — null-safe chain so unit tests (and
        // the occasional account with a detached user relation) don't
        // NPE before reaching the cap decision.
        $subscription = $account->user?->subscription;
        if ($subscription && ! $subscription->hasUnlimitedBalance()) {
            $maxBalance = $subscription->max_balance;
            if (Math::gt($margin, $maxBalance)) {
                $margin = $maxBalance;
            }
        }

        return $margin;
    }

    /**
     * Stop the workflow gracefully if margin is invalid.
     */
    public function complete(): void
    {
        if (! $this->hasValidMargin) {
            $this->stopJob();
        }
    }
}
