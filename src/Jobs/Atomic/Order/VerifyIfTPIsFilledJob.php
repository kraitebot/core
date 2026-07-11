<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\Order;

use Kraite\Core\Abstracts\BaseApiableJob;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Exceptions\NonNotifiableException;
use Kraite\Core\Models\Position;

/**
 * VerifyIfTPIsFilledJob
 *
 * Queries the exchange to verify the TP order status before proceeding with WAP.
 * If TP is already FILLED on the exchange, throws exception to abort WAP workflow.
 *
 * This handles the edge case where:
 * 1. LIMIT order fills
 * 2. Before sync-orders runs, TP also fills
 * 3. Both are detected in same sync cycle
 * 4. WAP workflow starts but TP is already filled
 *
 * By querying exchange first, we detect this and let close workflow handle it.
 */
final class VerifyIfTPIsFilledJob extends BaseApiableJob
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

    public function startOrFail(): bool
    {
        return $this->position->profitOrder() !== null;
    }

    public function computeApiable()
    {
        $profitOrder = $this->position->profitOrder();

        // Query exchange to get current status
        $apiResponse = $profitOrder->apiQuery();
        $exchangeStatus = $apiResponse->result['status'] ?? null;

        // For BitGet: If NOT_FOUND in pending list, check history (order may have filled and moved)
        if ($exchangeStatus === 'NOT_FOUND' && $profitOrder->is_algo) {
            $apiResponse = $profitOrder->apiQueryPlanOrderHistory();
            $exchangeStatus = $apiResponse->result['status'] ?? null;
        }

        // If TP is FILLED on exchange, abort WAP — but FIRST persist the
        // fill locally. We hold definitive exchange truth right here;
        // discarding it and betting on the WS stream / sync cron to
        // re-discover the fill left a window (reconciliation degraded)
        // where the local order stayed NEW and the position reverted to
        // 'active' — a phantom live position whose exchange side had
        // already closed. Writing FILLED through updateSaving fires the
        // OrderObserver's locked + deduped close dispatch organically
        // (waping is an active status, so it claims 'closing'), and the
        // WAP resolver's revert-to-active then no-ops on its own
        // onlyFromStatus='waping' guard. The observer remains the single
        // close-dispatch chokepoint — no parallel dispatch site here.
        if ($exchangeStatus === 'FILLED') {
            $profitOrder->updateSaving(['status' => 'FILLED']);

            throw new NonNotifiableException(
                "TP order #{$profitOrder->id} is already FILLED on exchange - aborting WAP, close workflow dispatched via observer"
            );
        }

        return [
            'position_id' => $this->position->id,
            'profit_order_id' => $profitOrder->id,
            'exchange_status' => $exchangeStatus,
            'message' => 'TP order verified - not filled, proceeding with WAP',
        ];
    }
}
