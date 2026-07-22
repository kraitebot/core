<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Lifecycles\Position;

use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Models\Position;

/**
 * DispatchPositionJob (Orchestrator)
 *
 * Orchestrator job that creates step(s) for dispatching a position to the exchange.
 * This is the base implementation - exchange-specific overrides define the actual steps.
 *
 * Typical flow (exchange-specific):
 * • Step 1: Verify trading pair not already open (showstopper)
 * • Step 2: Set margin mode (isolated/cross)
 * • Step 3: Set leverage
 * • Step 4: Place entry order
 *
 * Exchange overrides: Jobs/Lifecycles/Position/{Exchange}/DispatchPositionJob.php
 */
class DispatchPositionJob extends BaseQueueableJob
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
     * Guard: Stop if position is not ready to be dispatched.
     * Requires: direction, exchange_symbol_id, and status = 'new'.
     */
    public function startOrStop(): bool
    {
        return filled($this->position->direction)
            && filled($this->position->exchange_symbol_id)
            && $this->position->status === 'new'
            && $this->position->account->isOnActiveApiSystem();
    }

    public function compute()
    {
        // Base implementation - exchange-specific overrides will define the actual steps
        // This should never be called directly since all exchanges have their own implementation
        throw new \RuntimeException(
            'DispatchPositionJob must be overridden by exchange-specific implementation. '
            .'Exchange: '.$this->position->account->apiSystem->canonical
        );
    }
}
