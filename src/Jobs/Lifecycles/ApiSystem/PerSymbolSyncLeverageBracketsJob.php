<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Lifecycles\ApiSystem;

use InvalidArgumentException;
use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Jobs\Atomic\ExchangeSymbol\SyncLeverageBracketJob;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use StepDispatcher\Models\Step;

/**
 * Shared lifecycle for exchanges whose leverage endpoint requires one
 * request per symbol.
 */
abstract class PerSymbolSyncLeverageBracketsJob extends BaseQueueableJob
{
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
        if ($this->apiSystem->fresh()?->is_active !== true) {
            return [
                'exchange' => $this->apiSystem->canonical,
                'steps_created' => 0,
                'message' => 'API system is inactive',
            ];
        }

        $symbols = ExchangeSymbol::query()
            ->whereBelongsTo($this->apiSystem)
            ->where('is_marked_for_delisting', false)
            ->get();

        if ($symbols->isEmpty()) {
            return [
                'exchange' => $this->apiSystem->canonical,
                'steps_created' => 0,
                'message' => "No exchange symbols found for {$this->apiSystem->name}",
            ];
        }

        $batchSize = config()->integer('kraite.leverage_brackets.per_symbol_batch_size');
        if ($batchSize < 1) {
            throw new InvalidArgumentException('Per-symbol leverage bracket batch size must be at least 1.');
        }

        $this->buildChildChainOnce(function (string $blockUuid) use ($batchSize, $symbols): void {
            foreach ($symbols->values() as $position => $symbol) {
                Step::create([
                    'class' => SyncLeverageBracketJob::class,
                    'queue' => 'indicators',
                    'arguments' => ['exchangeSymbolId' => $symbol->id],
                    'block_uuid' => $blockUuid,
                    'index' => intdiv($position, $batchSize) + 1,
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
