<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\Position;

use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Models\ApiSnapshot;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\Math;
use Kraite\Core\Support\NotificationService;

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
     * Fire the `position_residual_detected` notification (severity=Critical,
     * so it maps to Pushover priority 2 / emergency) via NotificationService
     * so the throttling + logging pipeline is consistent with every other
     * canonical.
     */
    private function notifyResidualPosition(Position $position, string $amount): void
    {
        NotificationService::send(
            user: Kraite::admin(),
            canonical: 'position_residual_detected',
            referenceData: [
                'token' => $position->exchangeSymbol?->token,
                'pair' => $position->parsed_trading_pair,
                'direction' => mb_strtoupper((string) $position->direction),
                'position_id' => (int) $position->id,
                'residual_amount' => $amount,
            ],
            relatable: $position,
            cacheKeys: ['position' => $position->id],
        );
    }
}
