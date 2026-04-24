<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\Position;

use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSnapshot;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\Math;

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
        $margin = $this->calculateMarginWithSubscriptionCap($account, $direction);

        $this->hasValidMargin = Math::gt($margin, '0', 2);

        if (! $this->hasValidMargin) {
            return [
                'position_id' => $this->position->id,
                'stopped' => true,
                'reason' => 'Margin is zero or negative - no balance available',
                'calculated_margin' => $margin,
            ];
        }

        $profitPercentage = $account->profit_percentage;
        $indicatorsValues = $exchangeSymbol->indicators_values;
        $indicatorsTimeframe = $exchangeSymbol->indicators_timeframe;
        $totalLimitOrders = $exchangeSymbol->total_limit_orders ?? 4;

        // Leverage is set later by DetermineLeverageJob based on the
        // resolved margin + leverage brackets.
        $this->position->updateSaving([
            'margin' => $margin,
            'profit_percentage' => $profitPercentage,
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
            'profit_percentage' => (string) $profitPercentage,
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
        $balanceSnapshot = ApiSnapshot::getFrom($account, 'account-balance');
        $balance = $balanceSnapshot['available-balance']
            ?? $account->margin
            ?? '0';

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
