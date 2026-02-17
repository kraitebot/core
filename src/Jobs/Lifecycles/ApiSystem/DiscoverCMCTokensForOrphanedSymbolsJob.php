<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Lifecycles\ApiSystem;

use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Jobs\Models\ExchangeSymbol\DiscoverCMCTokenForExchangeSymbolJob;
use Kraite\Core\Models\ExchangeSymbol;
use StepDispatcher\Models\Step;

/**
 * DiscoverCMCTokensForOrphanedSymbolsJob
 *
 * Parent lifecycle job that creates child steps to discover CMC tokens
 * for all exchange symbols that don't have a symbol_id linked.
 *
 * This job should run after all exchange upsert jobs have completed,
 * so it can find all newly created orphaned symbols.
 */
final class DiscoverCMCTokensForOrphanedSymbolsJob extends BaseQueueableJob
{
    public function relatable()
    {
        return null;
    }

    public function compute()
    {
        // Get exchange symbols that:
        // 1. Don't have a symbol_id yet (orphaned)
        // 2. Haven't had CMC API called yet (avoid redundant API calls)
        $orphanedSymbols = ExchangeSymbol::whereNull('symbol_id')
            ->where(static function ($query) {
                $query->whereNull('api_statuses->cmc_api_called')
                    ->orWhere('api_statuses->cmc_api_called', false);
            })
            ->get();

        if ($orphanedSymbols->isEmpty()) {
            // No children to create - clear child_block_uuid so StepDispatcher
            // can mark this step as complete (otherwise it waits for non-existent children)
            $this->step->update(['child_block_uuid' => null]);

            // Check if there are orphaned symbols that were already processed
            $alreadyProcessedCount = ExchangeSymbol::whereNull('symbol_id')
                ->where('api_statuses->cmc_api_called', true)
                ->count();

            return [
                'orphaned_count' => 0,
                'already_processed' => $alreadyProcessedCount,
                'steps_created' => 0,
                'message' => $alreadyProcessedCount > 0
                    ? "No new orphaned symbols to process ({$alreadyProcessedCount} already checked via CMC API)"
                    : 'No orphaned exchange symbols found',
            ];
        }

        // Create a child step for each orphaned symbol.
        // Child steps use $this->uuid() (parent's child_block_uuid) as their block_uuid.
        // They don't need child_block_uuid since they're leaf steps.
        foreach ($orphanedSymbols as $exchangeSymbol) {
            Step::create([
                'class' => DiscoverCMCTokenForExchangeSymbolJob::class,
                'arguments' => [
                    'exchangeSymbolId' => $exchangeSymbol->id,
                ],
                'block_uuid' => $this->uuid(),
                'index' => 1,
            ]);
        }

        return [
            'orphaned_count' => $orphanedSymbols->count(),
            'steps_created' => $orphanedSymbols->count(),
            'message' => "CMC discovery steps created for {$orphanedSymbols->count()} orphaned symbols",
        ];
    }
}
