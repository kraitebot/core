<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Lifecycles\ApiSystem\Kucoin;

use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Jobs\Atomic\ExchangeSymbol\SyncLeverageBracketJob;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use StepDispatcher\Models\Step;

/**
 * SyncLeverageBracketsJob (Lifecycle - KuCoin Override)
 *
 * KuCoin's risk limit endpoint requires symbol in the URL path.
 * This override creates a child step for each exchange symbol to fetch brackets individually.
 */
class SyncLeverageBracketsJob extends BaseQueueableJob
{
    public ApiSystem $apiSystem;

    public function __construct(int $apiSystemId)
    {
        $this->apiSystem = ApiSystem::findOrFail($apiSystemId);
    }

    public function relatable()
    {
        return $this->apiSystem;
    }

    public function compute()
    {
        // Get all exchange symbols for this API system
        $symbols = ExchangeSymbol::where('api_system_id', $this->apiSystem->id)->get();

        if ($symbols->isEmpty()) {
            // No children to create - clear child_block_uuid so StepDispatcher
            // can mark this step as complete (otherwise it waits for non-existent children)
            $this->step->update(['child_block_uuid' => null]);

            return [
                'exchange' => $this->apiSystem->canonical,
                'steps_created' => 0,
                'message' => 'No exchange symbols found for KuCoin',
            ];
        }

        // Create a child step for each symbol (all at index 1 for parallel execution)
        foreach ($symbols as $symbol) {
            Step::create([
                'class' => SyncLeverageBracketJob::class,
                'arguments' => ['exchangeSymbolId' => $symbol->id],
                'block_uuid' => $this->uuid(),
                'index' => 1,
            ]);
        }

        return [
            'exchange' => $this->apiSystem->canonical,
            'steps_created' => $symbols->count(),
            'message' => "Created {$symbols->count()} per-symbol leverage brackets sync steps",
        ];
    }
}
