<?php

declare(strict_types=1);

namespace Kraite\Core\_Jobs\Lifecycles\Position;

use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\_Jobs\Models\Position\VerifyTradingPairNotOpenJob;
use Kraite\Core\Models\Position;
use StepDispatcher\Models\Step;
use Kraite\Core\Support\StepActions\Binance\PlaceOrderAction;
use Kraite\Core\Support\StepActions\Binance\SetLeverageAction;
use Kraite\Core\Support\StepActions\Binance\SetMarginAction;
use Kraite\Core\Support\Workflows\WorkflowBuilder;

/*
 * DispatchPositionJob
 *
 * Dispatches a single position for trading on the exchange.
 * • Step 1: VerifyTradingPairNotOpenJob - Verifies trading pair is not already open, stops if it is
 * • Step 2+: Exchange-specific workflow via WorkflowBuilder (margin, leverage, orders)
 */
final class DispatchPositionJob extends BaseQueueableJob
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
            && $this->position->status === 'new';
    }

    public function compute()
    {
        // Step 1: Verify trading pair is not already open on exchange
        Step::create([
            'class' => VerifyTradingPairNotOpenJob::class,
            'arguments' => [
                'positionId' => $this->position->id,
            ],
            'block_uuid' => $this->uuid(),
            'index' => 1,
        ]);

        // Step 2+: Exchange-specific workflow
        // WorkflowBuilder resolves actions to exchange-specific implementations
        WorkflowBuilder::for($this->position)
            ->inBlock($this->uuid())
            ->withPayload(['startIndex' => 2])
            ->action(SetMarginAction::class)
            ->action(SetLeverageAction::class)
            ->action(PlaceOrderAction::class);

        return [
            'position_id' => $this->position->id,
            'message' => 'Position dispatching initiated',
        ];
    }
}
