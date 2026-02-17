<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\Position;

use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Models\ApiSnapshot;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\User;
use Kraite\Core\Notifications\AlertNotification;
use Kraite\Core\Support\Math;

/**
 * VerifyPositionResidualAmountJob (Atomic)
 *
 * Checks if position still exists on exchange after close attempt.
 * Uses the account-positions snapshot from ApiSnapshot to verify.
 *
 * If residual amount is found, notifies admins with warning.
 * This indicates the close was incomplete and manual intervention may be needed.
 */
final class VerifyPositionResidualAmountJob extends BaseQueueableJob
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

    public function compute()
    {
        $position = $this->position;
        $tradingPair = mb_strtoupper(mb_trim($position->parsed_trading_pair));
        $hasResidual = false;
        $residualAmount = '0';

        // Get account-positions snapshot from ApiSnapshot
        $openPositions = ApiSnapshot::getFrom($position->account, 'account-positions');

        if (is_array($openPositions)) {
            // Check if position still exists with quantity > 0
            foreach ($openPositions as $key => $positionData) {
                // Handle both keyed (by symbol) and indexed arrays
                $symbol = $positionData['symbol'] ?? $key;
                $symbol = mb_strtoupper(mb_trim($symbol));

                if ($symbol !== $tradingPair) {
                    continue;
                }

                // Get position amount (different exchanges use different field names)
                $positionAmt = (string) ($positionData['positionAmt']
                    ?? $positionData['size']
                    ?? $positionData['qty']
                    ?? $positionData['available']
                    ?? '0');

                // Get absolute value without float casting
                $absAmount = Math::lt($positionAmt, '0')
                    ? Math::multiply($positionAmt, '-1')
                    : $positionAmt;

                if (Math::gt($absAmount, '0')) {
                    $hasResidual = true;
                    $residualAmount = $absAmount;
                    break;
                }
            }
        }

        if ($hasResidual) {
            // Notify admins about residual position
            $this->notifyResidualPosition($position, $residualAmount);
        }

        return [
            'position_id' => $position->id,
            'symbol' => $tradingPair,
            'has_residual' => $hasResidual,
            'residual_amount' => $residualAmount,
            'message' => $hasResidual
                ? "Warning: Residual amount {$residualAmount} found for {$tradingPair}"
                : 'Position fully closed - no residual',
        ];
    }

    /**
     * Notify admins about residual position.
     */
    private function notifyResidualPosition(Position $position, string $amount): void
    {
        $message = sprintf(
            '⚠️ Residual position detected for %s (Position ID: %d). Amount: %s. Manual intervention may be required.',
            $position->parsed_trading_pair,
            $position->id,
            $amount
        );

        // Notify all admin users via exceptions delivery group
        User::query()
            ->where('is_admin', true)
            ->where('is_active', true)
            ->get()
            ->each(function (User $user) use ($message) {
                $user->notify(new AlertNotification(
                    message: $message,
                    title: 'Residual Position Warning',
                    canonical: 'residual_position_detected',
                    deliveryGroup: 'exceptions'
                ));
            });
    }
}
