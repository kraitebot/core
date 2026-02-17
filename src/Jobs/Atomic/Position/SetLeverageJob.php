<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\Position;

use Kraite\Core\Abstracts\BaseApiableJob;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Models\Position;

/**
 * SetLeverageJob (Atomic)
 *
 * Sets the leverage ratio for a position's symbol on the exchange.
 * Uses the leverage value stored on the position (set by DetermineLeverageJob).
 *
 * Must run AFTER DetermineLeverageJob (position.leverage must be set).
 */
class SetLeverageJob extends BaseApiableJob
{
    public Position $position;

    public function __construct(int $positionId)
    {
        $this->position = Position::findOrFail($positionId);
    }

    public function assignExceptionHandler(): void
    {
        $this->exceptionHandler = BaseExceptionHandler::make(
            $this->position->account->apiSystem->canonical
        )->withAccount($this->position->account);
    }

    public function relatable()
    {
        return $this->position;
    }

    /**
     * Position must have leverage set by DetermineLeverageJob.
     */
    public function startOrFail(): bool
    {
        return $this->position->leverage !== null;
    }

    public function computeApiable()
    {
        $tradingPair = $this->position->exchangeSymbol->parsed_trading_pair;
        $direction = $this->position->direction;

        // Use leverage determined by DetermineLeverageJob
        $leverage = (int) $this->position->leverage;

        $apiResponse = $this->position->apiUpdateLeverageRatio($leverage);

        return [
            'position_id' => $this->position->id,
            'trading_pair' => $tradingPair,
            'direction' => $direction,
            'leverage' => $leverage,
            'message' => "Leverage set to {$leverage}x for {$tradingPair} ({$direction})",
            'api_response' => $apiResponse->result,
        ];
    }
}
