<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\Position;

use Kraite\Core\Abstracts\BaseApiableJob;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Exceptions\NonNotifiableException;
use Kraite\Core\Models\Position;

/**
 * VerifyPositionStillOpenJob (Atomic)
 *
 * Guards PlaceStopLossOrderJob against the fast-trade race where the
 * TP fills within milliseconds of the market entry. Observed on
 * thin-book / volatile tokens (BSB #109 hit this — MARKET at 0.39791,
 * TP filled almost instantly, SL placement then rejected by Binance
 * with code -4509 "Time in Force GTE can only be used with open
 * positions"). Without a pre-gate the exception cascades into the
 * cancel workflow which itself runs against a position that is
 * already closed on the exchange, producing a confusing secondary
 * "algo id required" error on the cleanup path.
 *
 * Contract:
 * - Queries the exchange for the account's current positions.
 * - If the position for this symbol + direction is still open
 *   (|positionAmt| > epsilon), the job completes and the workflow
 *   proceeds to PlaceStopLossOrderJob.
 * - If the position is absent or fully closed, the job throws a
 *   NonNotifiableException so the DispatchPositionJob cascade fires
 *   CancelPositionJob — which detects the no-op exchange state
 *   gracefully (ClosePositionAtomicallyJob already handles "position
 *   already closed via TP/SL fill") and drives the position to a
 *   terminal state without the -4509 noise.
 *
 * Runs on every exchange via JobProxy routing from the matching
 * lifecycle wrapper. `apiQueryPositions()` is a uniform account-level
 * helper so the atomic itself doesn't need per-exchange branches.
 */
final class VerifyPositionStillOpenJob extends BaseApiableJob
{
    public Position $position;

    public function __construct(int $positionId)
    {
        $this->position = Position::findOrFail($positionId);
    }

    public function assignExceptionHandler(): void
    {
        $canonical = $this->position->account->apiSystem->canonical;
        $this->exceptionHandler = BaseExceptionHandler::make($canonical)
            ->withAccount($this->position->account);
    }

    public function relatable()
    {
        return $this->position;
    }

    public function computeApiable()
    {
        $position = $this->position;
        $snapshot = $this->resolvePositionSnapshot();

        $rawAmt = $snapshot['positionAmt'] ?? $snapshot['total'] ?? 0;
        $positionAmt = abs((float) $rawAmt);

        if ($positionAmt > 0.0001) {
            return [
                'position_id' => $position->id,
                'symbol' => $position->parsed_trading_pair,
                'direction' => mb_strtoupper((string) $position->direction),
                'position_amt' => $positionAmt,
                'message' => 'Position confirmed open on exchange, SL placement may proceed',
            ];
        }

        throw new NonNotifiableException(sprintf(
            'Position #%d %s %s closed before stop-loss placement '.
            '(fast-trade race: TP filled between market entry and SL step) — '.
            'cancel workflow will settle the local state.',
            $position->id,
            mb_strtoupper((string) $position->direction),
            $position->parsed_trading_pair,
        ));
    }

    /**
     * Find the account-level snapshot entry that matches this position.
     * Mirrors the shape used in ClosePositionAtomicallyJob so the two
     * reads stay consistent across exchanges.
     *
     * @return array<string, mixed>
     */
    private function resolvePositionSnapshot(): array
    {
        $accountPositions = $this->position->account->apiQueryPositions()->result ?? [];

        $symbol = mb_strtoupper((string) $this->position->parsed_trading_pair);
        $direction = mb_strtoupper((string) $this->position->direction);
        $positionKey = "{$symbol}:{$direction}";

        $snapshot = $accountPositions[$positionKey] ?? null;

        return is_array($snapshot) ? $snapshot : [];
    }
}
