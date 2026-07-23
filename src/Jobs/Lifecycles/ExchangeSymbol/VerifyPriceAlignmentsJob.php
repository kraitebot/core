<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Lifecycles\ExchangeSymbol;

use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Jobs\Atomic\ExchangeSymbol\VerifyPriceAlignmentJob;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Position;
use StepDispatcher\Models\Step;

/**
 * VerifyPriceAlignmentsJob (Lifecycle)
 *
 * Parent that spawns a per-symbol price-alignment check for every non-Binance
 * exchange symbol whose token NAME diverges from its Binance same-asset sibling
 * (matched on symbol_id + quote) — exactly the rows where the two contracts may
 * be different units (Binance `1000FLOKI` vs KuCoin `FLOKI`, both symbol_id 72).
 *
 * Same-name siblings share the contract convention, so their replicated price is
 * correct by construction and they are left aligned (the column defaults true).
 * Only the naming-divergent set needs a live price comparison.
 *
 * Runs at the tail of the symbol refresh (after CMC discovery has resolved
 * symbol_id). Self-elects to parent only when there is work, so an empty
 * naming-divergent set does not zombie the step.
 */
final class VerifyPriceAlignmentsJob extends BaseQueueableJob
{
    public function compute()
    {
        $candidateIds = $this->namingDivergentCandidateIds();

        if ($candidateIds === null) {
            return ['skipped' => 'No Binance api_system'];
        }

        if ($candidateIds->isEmpty()) {
            return [
                'steps_created' => 0,
                'message' => 'No naming-divergent symbols to verify',
            ];
        }

        $this->buildChildChainOnce(function (string $childBlockUuid) use ($candidateIds): void {
            foreach ($candidateIds as $exchangeSymbolId) {
                Step::create([
                    'class' => VerifyPriceAlignmentJob::class,
                    'queue' => 'cronjobs',
                    'arguments' => ['exchangeSymbolId' => (int) $exchangeSymbolId],
                    'block_uuid' => $childBlockUuid,
                    'index' => 1,
                ]);
            }
        });

        return [
            'steps_created' => $candidateIds->count(),
            'message' => 'Created price-alignment verification steps for naming-divergent symbols',
        ];
    }

    /**
     * Non-Binance exchange symbols whose token NAME diverges from their Binance
     * same-asset sibling (matched on symbol_id + quote) — the only rows whose
     * contract unit (and replicated price) may not match Binance. Same-name
     * siblings share the contract convention and are left aligned. Returns null
     * when Binance has no api_system row.
     *
     * @return Collection<int, int>|null
     */
    public function namingDivergentCandidateIds(): ?Collection
    {
        $binanceSystemId = ApiSystem::canonical('binance')->value('id');

        if ($binanceSystemId === null) {
            return null;
        }

        return ExchangeSymbol::query()
            ->onActiveApiSystem()
            ->where('api_system_id', '!=', $binanceSystemId)
            ->needsOperationalMonitoring()
            ->whereNotNull('symbol_id')
            ->whereExists(function (QueryBuilder $query) use ($binanceSystemId): void {
                $openedStatuses = (new Position)->openedStatuses();

                $query->from('exchange_symbols as binance_es')
                    ->where('binance_es.api_system_id', $binanceSystemId)
                    ->where(function (QueryBuilder $query) use ($openedStatuses): void {
                        $query->where(function (QueryBuilder $query): void {
                            $query->where('binance_es.is_marked_for_delisting', false)
                                ->where(function (QueryBuilder $query): void {
                                    $query->whereNull('binance_es.delivery_at')
                                        ->orWhere('binance_es.delivery_at', '>', now());
                                });
                        })->orWhereExists(static fn (QueryBuilder $positions) => $positions
                            ->selectRaw('1')
                            ->from('positions')
                            ->whereColumn('positions.exchange_symbol_id', 'exchange_symbols.id')
                            ->whereIn('positions.status', $openedStatuses));
                    })
                    ->whereColumn('binance_es.symbol_id', 'exchange_symbols.symbol_id')
                    ->whereColumn('binance_es.quote', 'exchange_symbols.quote')
                    // NAME divergence is the trigger — same asset, different
                    // ticker, so the contract unit may not match.
                    ->whereColumn('binance_es.token', '!=', 'exchange_symbols.token');
            })
            ->pluck('id');
    }
}
