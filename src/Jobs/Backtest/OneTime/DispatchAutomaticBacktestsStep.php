<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Backtest\OneTime;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ExchangeSymbol;
use StepDispatcher\Models\Step;

final class DispatchAutomaticBacktestsStep extends BaseQueueableJob
{
    public Account $account;

    public int $concurrency;

    public int $maxMonths;

    public ?int $limit;

    public function __construct(
        int $accountId,
        public bool $apply = false,
        int $concurrency = 2,
        int $maxMonths = 24,
        ?int $limit = null,
    ) {
        $this->account = Account::findOrFail($accountId);
        $this->concurrency = max(1, min(20, $concurrency));
        $this->maxMonths = max(1, min(48, $maxMonths));
        $this->limit = $limit !== null ? max(1, $limit) : null;
        $this->retries = 3;
    }

    public function relatable(): Account
    {
        return $this->account;
    }

    /**
     * @return array{tokens_selected: int, steps_created: int, chain_created: bool, apply: bool, concurrency: int}
     */
    public function compute(): array
    {
        $symbols = $this->pendingSymbols();

        if ($symbols->isEmpty()) {
            return [
                'tokens_selected' => 0,
                'steps_created' => 0,
                'chain_created' => false,
                'apply' => $this->apply,
                'concurrency' => $this->concurrency,
            ];
        }

        $chainCreated = $this->buildChildChainOnce(function (string $childBlockUuid) use ($symbols): void {
            $waveIndex = 1;

            foreach ($symbols->chunk($this->concurrency) as $wave) {
                foreach ($wave as $symbol) {
                    Step::create([
                        'class' => AutomaticBacktestLifecycleStep::class,
                        'queue' => 'indicators',
                        'relatable_type' => ExchangeSymbol::class,
                        'relatable_id' => $symbol->id,
                        'arguments' => [
                            'exchangeSymbolId' => $symbol->id,
                            'accountId' => $this->account->id,
                            'apply' => $this->apply,
                            'maxMonths' => $this->maxMonths,
                        ],
                        'block_uuid' => $childBlockUuid,
                        'index' => $waveIndex,
                    ]);
                }

                $waveIndex++;
            }
        });

        return [
            'tokens_selected' => $symbols->count(),
            'steps_created' => $symbols->count(),
            'chain_created' => $chainCreated,
            'apply' => $this->apply,
            'concurrency' => $this->concurrency,
        ];
    }

    /**
     * Select one canonical Binance pair per pending token, preferring USDT.
     *
     * @return Collection<int, ExchangeSymbol>
     */
    private function pendingSymbols(): Collection
    {
        $symbols = ExchangeSymbol::query()
            ->whereHas(
                'apiSystem',
                static fn (Builder $apiSystems): Builder => $apiSystems
                    ->where('is_active', true)
                    ->where('canonical', 'binance'),
            )
            ->whereNotNull('symbol_id')
            ->where('was_backtesting_approved', false)
            ->whereNull('backtesting_review_status')
            ->orderByRaw("CASE UPPER(quote) WHEN 'USDT' THEN 0 WHEN 'USDC' THEN 1 ELSE 2 END")
            ->orderBy('id')
            ->get()
            ->unique('symbol_id')
            ->values();

        if ($this->limit !== null) {
            $symbols = $symbols->take($this->limit)->values();
        }

        return $symbols;
    }
}
