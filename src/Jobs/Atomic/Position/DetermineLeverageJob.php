<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\Position;

use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\Math;

/**
 * DetermineLeverageJob (Atomic)
 *
 * Determines the optimal leverage for a position based on:
 * - Position margin (calculated by PreparePositionDataJob)
 * - Exchange symbol's leverage brackets
 * - Account's max leverage setting (position_leverage_long/short)
 *
 * The algorithm finds the highest leverage where:
 * - position_notional = margin × leverage
 * - position_notional fits under the bracket's notionalCap
 * - leverage <= bracket's initialLeverage
 * - leverage <= account's max leverage setting
 *
 * Must run AFTER PreparePositionDataJob (margin must be set).
 * Must run BEFORE SetLeverageJob (determines what leverage to set on exchange).
 */
class DetermineLeverageJob extends BaseQueueableJob
{
    public Position $position;

    public function __construct(int $positionId)
    {
        $this->position = Position::findOrFail($positionId);
    }

    public function relatable()
    {
        return $this->position;
    }

    /**
     * Position must have margin set by PreparePositionDataJob.
     */
    public function startOrFail(): bool
    {
        return $this->position->margin !== null;
    }

    public function compute()
    {
        $account = $this->position->account;
        $exchangeSymbol = $this->position->exchangeSymbol;
        $direction = $this->position->direction;
        $margin = (string) $this->position->margin;

        // Get account's max leverage for this direction
        $accountMaxLeverage = $direction === 'LONG'
            ? (int) $account->position_leverage_long
            : (int) $account->position_leverage_short;

        // Get leverage brackets from exchange symbol
        $brackets = $exchangeSymbol->leverage_brackets;

        if (empty($brackets)) {
            // No brackets available - fall back to account's max leverage
            $this->position->updateSaving(['leverage' => $accountMaxLeverage]);

            return [
                'position_id' => $this->position->id,
                'symbol' => $exchangeSymbol->parsed_trading_pair,
                'direction' => $direction,
                'margin' => $margin,
                'leverage' => $accountMaxLeverage,
                'reason' => 'no_brackets_available',
                'message' => "No leverage brackets found, using account max: {$accountMaxLeverage}x",
            ];
        }

        // Determine optimal leverage
        $result = $this->getMaxLeverageForMargin($margin, $brackets, $accountMaxLeverage);

        // Update position with determined leverage
        $this->position->updateSaving(['leverage' => $result['leverage']]);

        return [
            'position_id' => $this->position->id,
            'symbol' => $exchangeSymbol->parsed_trading_pair,
            'direction' => $direction,
            'margin' => $margin,
            'account_max_leverage' => $accountMaxLeverage,
            'leverage' => $result['leverage'],
            'notional' => $result['notional'],
            'bracket' => $result['bracket'],
            'reason' => $result['reason'],
            'message' => "Leverage determined: {$result['leverage']}x (notional: {$result['notional']})",
        ];
    }

    /**
     * Calculate the maximum leverage that fits within bracket constraints.
     *
     * Algorithm:
     * 1. Sort brackets by notionalCap descending (lowest leverage / highest cap first)
     * 2. Iterate through brackets, trying each bracket's max leverage (capped by account)
     * 3. If notional (margin × leverage) fits under cap, update best leverage
     * 4. Stop when we reach account's max leverage or when a bracket fails
     *
     * @param  string  $margin  Position margin
     * @param  array  $brackets  Leverage brackets from exchange symbol
     * @param  int  $accountMaxLeverage  Account's max leverage setting
     * @return array{leverage: int, notional: string, bracket: int|null, reason: string}
     */
    public function getMaxLeverageForMargin(string $margin, array $brackets, int $accountMaxLeverage): array
    {
        // Sort brackets by notionalCap descending (largest cap / lowest leverage first)
        usort($brackets, function ($a, $b) {
            return Math::cmp((string) $b['notionalCap'], (string) $a['notionalCap']);
        });

        $bestLeverage = 1;
        $bestNotional = $margin;
        $bestBracket = null;
        $reason = 'fallback';

        foreach ($brackets as $bracket) {
            $bracketMaxLev = (int) $bracket['initialLeverage'];

            // Use the minimum of account's max and bracket's max
            $leverage = min($accountMaxLeverage, $bracketMaxLev);
            $notional = Math::mul($margin, (string) $leverage);
            $cap = (string) $bracket['notionalCap'];

            // Check if notional fits under this bracket's cap
            if (Math::lt($notional, $cap)) {
                $bestLeverage = $leverage;
                $bestNotional = $notional;
                $bestBracket = (int) $bracket['bracket'];

                // Early exit: reached account's max leverage, can't do better
                if ($bestLeverage >= $accountMaxLeverage) {
                    $reason = 'account_max_reached';

                    break;
                }

                $reason = 'bracket_constrained';
            } else {
                // Failed to fit in this bracket - stop here
                // (higher leverage brackets will have smaller caps, so they'll also fail)
                break;
            }
        }

        return [
            'leverage' => $bestLeverage,
            'notional' => $bestNotional,
            'bracket' => $bestBracket,
            'reason' => $reason,
        ];
    }
}
