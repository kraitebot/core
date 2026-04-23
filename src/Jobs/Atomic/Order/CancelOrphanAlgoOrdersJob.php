<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\Order;

use Kraite\Core\Abstracts\BaseApiableJob;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Models\Position;

/**
 * CancelOrphanAlgoOrdersJob (Atomic)
 *
 * Default, cross-exchange implementation: no-op. This base is only
 * invoked for exchanges whose native "modify algo order" flow is
 * in-place on the same exchange order id — i.e. no ghost orders land
 * on exchange when the user moves an algo order on the UI. Exchanges
 * where the UI's "modify" actually cancels the original and creates a
 * new algo order behind the scenes (Binance) override this base and do
 * the ghost-cleanup before the subsequent recreation step.
 *
 * Called by SmartReplaceOrdersJob as the first step of the replacement
 * block so any unknown algo order the exchange carries for this
 * symbol is cancelled before our own recreation lands — otherwise we
 * end up with two stops active on the exchange (ours at reference
 * values plus the ghost the user just moved into existence).
 *
 * @see Kraite\Core\Jobs\Atomic\Order\Binance\CancelOrphanAlgoOrdersJob
 */
class CancelOrphanAlgoOrdersJob extends BaseApiableJob
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

    public function relatable(): Position
    {
        return $this->position;
    }

    /**
     * @return array<string, mixed>
     */
    public function computeApiable(): array
    {
        return [
            'position_id' => $this->position->id,
            'symbol' => $this->position->parsed_trading_pair,
            'cancelled_count' => 0,
            'cancelled' => [],
            'message' => 'Exchange does not require orphan-algo cleanup (base no-op)',
        ];
    }
}
