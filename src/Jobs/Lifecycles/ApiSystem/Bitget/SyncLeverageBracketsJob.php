<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Lifecycles\ApiSystem\Bitget;

use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Jobs\Atomic\ExchangeSymbol\SyncLeverageBracketJob;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use StepDispatcher\Models\Step;

/**
 * SyncLeverageBracketsJob (Lifecycle - BitGet Override)
 *
 * BitGet's position tier endpoint requires symbol parameter.
 * This override creates a child step for each exchange symbol to fetch brackets individually.
 */
final class SyncLeverageBracketsJob extends BaseQueueableJob
{
    /**
     * Symbols are chunked into sequential step batches so StepDispatcher
     * only promotes ~N peers to Pending at a time instead of the full
     * fan-out. Without this, 500+ steps become eligible on the same tick
     * and Horizon's `indicators` worker pool races `canDispatch`
     * past the exchange cap — the observed 14:15 burst pattern. A batch
     * of 5 caps the concurrent-worker-vs-throttler race to 5 and keeps
     * each rolled-back 429 contained to a single step's dispatch_after
     * rather than synchronising the whole fleet.
     */
    private const BATCH_SIZE = 5;

    public ApiSystem $apiSystem;

    public function __construct(int $apiSystemId)
    {
        $this->apiSystem = ApiSystem::findOrFail($apiSystemId);
    }

    public function relatable(): ApiSystem
    {
        return $this->apiSystem;
    }

    /** @return array{exchange: string, steps_created: int, message: string} */
    public function compute(): array
    {
        // Skip symbols already flagged for delisting — the exchange will
        // answer "contract removed" for them and fail the whole parent step
        // every hour until they're cleaned up.
        $symbols = ExchangeSymbol::where('api_system_id', $this->apiSystem->id)
            ->where('is_marked_for_delisting', false)
            ->get();

        if ($symbols->isEmpty()) {
            return [
                'exchange' => $this->apiSystem->canonical,
                'steps_created' => 0,
                'message' => 'No exchange symbols found for BitGet',
            ];
        }

        $this->buildChildChainOnce(function (string $blockUuid) use ($symbols): void {
            foreach ($symbols->values() as $position => $symbol) {
                Step::create([
                    'class' => SyncLeverageBracketJob::class,
                    'queue' => 'indicators',
                    'arguments' => ['exchangeSymbolId' => $symbol->id],
                    'block_uuid' => $blockUuid,
                    'index' => intdiv($position, self::BATCH_SIZE) + 1,
                ]);
            }
        });

        return [
            'exchange' => $this->apiSystem->canonical,
            'steps_created' => $symbols->count(),
            'message' => "Created {$symbols->count()} per-symbol leverage brackets sync steps",
        ];
    }
}
