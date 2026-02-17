<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Lifecycles\Position;

use Kraite\Core\Abstracts\BaseApiableJob;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\Proxies\JobProxy;

/**
 * ReplacePositionOrdersJob (Orchestrator)
 *
 * Replaces missing orders when a profit/stop order is CANCELLED or EXPIRED
 * but the position still exists on the exchange.
 *
 * Base implementation — exchange-specific overrides define the actual steps.
 *
 * Typical flow (exchange-specific):
 * 1. UpdatePositionStatusJob → status='syncing'
 * 2. CancelPositionOpenOrdersJob → cancel remaining orders on exchange
 * 3. SyncPositionOrdersJob → sync all orders from exchange
 * 4. PlaceLimitOrdersJob → recreate limit ladder
 * 5. PlaceProfitOrderJob → recreate take-profit order
 * 6. PlaceStopLossOrderJob → recreate stop-loss order
 * 7. ActivatePositionJob → validate orders, set status='active'
 *
 * resolve-exception: CancelPositionJob → if replacement fails, cancel the position
 *
 * Exchange overrides: Jobs/Lifecycles/Position/{Exchange}/ReplacePositionOrdersJob.php
 */
class ReplacePositionOrdersJob extends BaseApiableJob
{
    public Position $position;

    public ?string $message;

    public function __construct(int $positionId, ?string $message = null)
    {
        $this->position = Position::findOrFail($positionId);
        $this->message = $message;
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

    /**
     * Guard: Only run if position is still in an active status.
     */
    public function startOrFail(): bool
    {
        return in_array($this->position->status, $this->position->activeStatuses(), strict: true);
    }

    public function computeApiable()
    {
        // Base implementation — exchange-specific overrides define actual steps
        throw new \RuntimeException(
            'ReplacePositionOrdersJob must be overridden by exchange-specific implementation. '
            . 'Exchange: ' . $this->position->account->apiSystem->canonical
        );
    }
}
