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
            // No children to create — DON'T elect to parent mode. The step
            // completes as an orphan, no zombie. (See ~/steps-dispatcher/issue.md.)
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

        // Self-elect to parent mode now that we have children to spawn.
        // Idempotent: returns existing child_block_uuid on retry so re-runs
        // don't lose the link to children created on the first attempt.
        $childBlockUuid = $this->step->child_block_uuid ?? $this->step->makeItAParent();

        foreach ($orphanedSymbols as $exchangeSymbol) {
            Step::create([
                'class' => DiscoverCMCTokenForExchangeSymbolJob::class,
                'queue' => 'indicators',
                'arguments' => [
                    'exchangeSymbolId' => $exchangeSymbol->id,
                ],
                'block_uuid' => $childBlockUuid,
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
