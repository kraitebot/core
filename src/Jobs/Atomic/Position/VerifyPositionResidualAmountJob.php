<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\Position;

use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Enums\PositionPresence;
use Kraite\Core\Models\ApiSnapshot;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\Math;
use Kraite\Core\Support\NotificationService;
use Kraite\Core\Support\PositionSnapshot;
use UnexpectedValueException;

/**
 * VerifyPositionResidualAmountJob (Atomic)
 *
 * Checks if position still exists on exchange after close attempt.
 * Uses the account-positions snapshot from ApiSnapshot to verify.
 *
 * If residual amount is found, notifies admins and fails the lifecycle before
 * it can mark the local position closed.
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

    public function compute(): array
    {
        $position = $this->position;
        $tradingPair = mb_strtoupper(mb_trim($position->parsed_trading_pair));
        $openPositions = ApiSnapshot::getFrom($position->account, 'account-positions');

        if (! is_array($openPositions)) {
            throw new UnexpectedValueException(
                "Trusted account-positions snapshot is missing for position #{$position->id}.",
            );
        }

        $snapshot = PositionSnapshot::fromValidatedResult($openPositions);
        $presence = $snapshot->presenceOf($position);

        if ($presence === PositionPresence::Unknown) {
            throw new UnexpectedValueException(
                "Trusted account-positions snapshot is malformed for position #{$position->id}.",
            );
        }

        if ($presence === PositionPresence::Open) {
            $matchingPosition = $snapshot->matchingPosition($position);

            if (! is_array($matchingPosition)) {
                throw new UnexpectedValueException(
                    "Trusted account-positions snapshot is malformed for position #{$position->id}.",
                );
            }

            $residualAmount = $this->residualAmount($matchingPosition);
            $this->notifyResidualPosition($position, $residualAmount);

            throw new UnexpectedValueException(
                "Residual amount {$residualAmount} found for {$tradingPair}.",
            );
        }

        return [
            'position_id' => $position->id,
            'symbol' => $tradingPair,
            'has_residual' => false,
            'residual_amount' => '0',
            'message' => 'Position fully closed - no residual',
        ];
    }

    /** @param array<string, mixed> $positionData */
    private function residualAmount(array $positionData): string
    {
        $amount = (string) ($positionData['positionAmt']
            ?? $positionData['size']
            ?? $positionData['total']
            ?? $positionData['currentQty']
            ?? $positionData['qty']
            ?? $positionData['available']
            ?? $positionData['contracts']
            ?? '0');

        return Math::lt($amount, '0')
            ? mb_ltrim($amount, '-')
            : $amount;
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
