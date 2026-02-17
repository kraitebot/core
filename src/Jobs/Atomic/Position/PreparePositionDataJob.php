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
class PreparePositionDataJob extends BaseQueueableJob
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

        // 1. Calculate margin with subscription cap
        $margin = $this->calculateMarginWithSubscriptionCap($account);

        // Check if margin is valid (> 0)
        $this->hasValidMargin = Math::gt($margin, '0', 2);

        // Stop gracefully if margin is 0 or negative (no balance available)
        if (! $this->hasValidMargin) {
            return [
                'position_id' => $this->position->id,
                'stopped' => true,
                'reason' => 'Margin is zero or negative - no balance available',
                'calculated_margin' => $margin,
            ];
        }

        // 2. Get profit percentage from account
        $profitPercentage = $account->profit_percentage;

        // 3. Get indicators from exchange symbol
        $indicatorsValues = $exchangeSymbol->indicators_values;
        $indicatorsTimeframe = $exchangeSymbol->indicators_timeframe;

        // 4. Get total limit orders from exchange symbol (fallback to 4)
        $totalLimitOrders = $exchangeSymbol->total_limit_orders ?? 4;

        // Update position with all data (leverage is set by DetermineLeverageJob)
        $this->position->updateSaving([
            'margin' => $margin,
            'profit_percentage' => $profitPercentage,
            'indicators_values' => $indicatorsValues,
            'indicators_timeframe' => $indicatorsTimeframe,
            'total_limit_orders' => $totalLimitOrders,
        ]);

        // Transition to 'opening' status
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
     * Calculate margin with subscription cap.
     *
     * Formula: balance × (max_position_percentage / 100)
     * Cap: subscription.max_balance (if not unlimited)
     */
    public function calculateMarginWithSubscriptionCap(Account $account): string
    {
        // Get account balance from ApiSnapshot (stored by VerifyMinAccountBalanceJob)
        $balanceSnapshot = ApiSnapshot::getFrom($account, 'account-balance');
        $balance = $balanceSnapshot['available-balance']
            ?? $account->margin
            ?? '0';

        // Calculate position margin: balance × (max_position_percentage / 100)
        $maxPct = $account->max_position_percentage ?? '5.00';
        $margin = Math::mul($balance, Math::div($maxPct, '100'));

        // Check subscription cap
        $subscription = $account->user->subscription;
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
