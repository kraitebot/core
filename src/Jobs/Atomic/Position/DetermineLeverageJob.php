<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\Position;

use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\MarketRegime\Bscs;
use Kraite\Core\Support\Math;
use RuntimeException;

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
 * The bracket/account result is then scaled DOWN by the Phase 3 regime
 * BSCS leverage policy so the liquidation cliff
 * moves further from entry as the BSCS regime worsens — floored and
 * clamped to a minimum of 1x.
 *
 * Must run AFTER PreparePositionDataJob (margin must be set).
 * Must run BEFORE SetLeverageJob (determines what leverage to set on exchange).
 */
final class DetermineLeverageJob extends BaseQueueableJob
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
            // No brackets available - fall back to account's max leverage,
            // then apply the regime ramp on top of it.
            $ramp = $this->applyRegimeLeverageRamp($accountMaxLeverage);
            $this->position->updateSaving(['leverage' => $ramp['leverage']]);

            return [
                'position_id' => $this->position->id,
                'symbol' => $exchangeSymbol->parsed_trading_pair,
                'direction' => $direction,
                'margin' => $margin,
                'base_leverage' => $accountMaxLeverage,
                'regime_ratio' => $ramp['ratio'],
                'bscs_score' => $ramp['bscs_score'],
                'leverage' => $ramp['leverage'],
                'reason' => 'no_brackets_available',
                'message' => "No leverage brackets found, account max {$accountMaxLeverage}x → regime-scaled {$ramp['leverage']}x",
            ];
        }

        // Determine optimal leverage from brackets, then scale it down by
        // the current BSCS regime.
        $result = $this->getMaxLeverageForMargin($margin, $brackets, $accountMaxLeverage);
        $ramp = $this->applyRegimeLeverageRamp($result['leverage']);

        $this->position->updateSaving(['leverage' => $ramp['leverage']]);

        return [
            'position_id' => $this->position->id,
            'symbol' => $exchangeSymbol->parsed_trading_pair,
            'direction' => $direction,
            'margin' => $margin,
            'account_max_leverage' => $accountMaxLeverage,
            'base_leverage' => $result['leverage'],
            'regime_ratio' => $ramp['ratio'],
            'bscs_score' => $ramp['bscs_score'],
            'leverage' => $ramp['leverage'],
            'notional' => $result['notional'],
            'bracket' => $result['bracket'],
            'reason' => $result['reason'],
            'message' => "Leverage determined: base {$result['leverage']}x → regime-scaled {$ramp['leverage']}x (notional: {$result['notional']})",
        ];
    }

    /**
     * Apply the Phase 3 regime leverage ramp on top of the
     * bracket/account-determined base leverage. The scaled value is
     * floored and clamped to a minimum of 1x so it can never reach an
     * invalid 0. Calm regime returns the base unchanged.
     *
     * @return array{base: int, ratio: float, leverage: int, bscs_score: int|null}
     */
    public function applyRegimeLeverageRamp(int $baseLeverage): array
    {
        $adjustment = Bscs::forAccount($this->position->account)
            ->leverage()
            ->adjust($baseLeverage);

        return [
            'base' => $baseLeverage,
            'ratio' => $adjustment->ratio(),
            'leverage' => $adjustment->effective(),
            'bscs_score' => $adjustment->score(),
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
        $this->validateLeverageBrackets($brackets);

        // Sort brackets by notionalCap descending (largest cap / lowest leverage first)
        usort($brackets, function (array $a, array $b): int {
            if ($a['notionalCap'] === null) {
                return $b['notionalCap'] === null ? 0 : -1;
            }

            if ($b['notionalCap'] === null) {
                return 1;
            }

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
            $cap = $bracket['notionalCap'];

            // Check if notional fits under this bracket's cap
            if ($cap === null || Math::lte($notional, (string) $cap)) {
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

    private function validateLeverageBrackets(array $brackets): void
    {
        foreach ($brackets as $index => $bracket) {
            $hasRequiredKeys = is_array($bracket)
                && array_key_exists('bracket', $bracket)
                && array_key_exists('initialLeverage', $bracket)
                && array_key_exists('notionalCap', $bracket);

            if (! $hasRequiredKeys
                || ! is_numeric($bracket['bracket'])
                || (int) $bracket['bracket'] < 1
                || ! is_numeric($bracket['initialLeverage'])
                || (int) $bracket['initialLeverage'] < 1
                || ($bracket['notionalCap'] !== null
                    && (! is_numeric($bracket['notionalCap'])
                        || Math::lte((string) $bracket['notionalCap'], '0')))) {
                throw new RuntimeException("Invalid leverage bracket at index {$index}.");
            }
        }
    }
}
