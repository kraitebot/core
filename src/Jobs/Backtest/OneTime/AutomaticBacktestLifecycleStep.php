<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Backtest\OneTime;

use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Jobs\Backtest\EnsureBacktestCandleCoverageStep;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ExchangeSymbol;
use StepDispatcher\Models\Step;

final class AutomaticBacktestLifecycleStep extends BaseQueueableJob
{
    public ExchangeSymbol $exchangeSymbol;

    public Account $account;

    public int $maxMonths;

    public function __construct(
        int $exchangeSymbolId,
        int $accountId,
        public bool $apply = false,
        int $maxMonths = 24,
    ) {
        $this->exchangeSymbol = ExchangeSymbol::findOrFail($exchangeSymbolId);
        $this->account = Account::findOrFail($accountId);
        $this->maxMonths = max(1, min(48, $maxMonths));
        $this->retries = 3;
    }

    public function relatable(): ExchangeSymbol
    {
        return $this->exchangeSymbol;
    }

    /**
     * @return array{pair: string, steps_created: int, chain_created: bool, apply: bool}
     */
    public function compute(): array
    {
        $chainCreated = $this->buildChildChainOnce(function (string $childBlockUuid): void {
            Step::create([
                'class' => EnsureBacktestCandleCoverageStep::class,
                'queue' => 'indicators',
                'relatable_type' => ExchangeSymbol::class,
                'relatable_id' => $this->exchangeSymbol->id,
                'arguments' => [
                    'exchangeSymbolId' => $this->exchangeSymbol->id,
                    'timeframe' => '1d',
                    'maxMonths' => $this->maxMonths,
                    'gapLookbackTs' => null,
                ],
                'block_uuid' => $childBlockUuid,
                'index' => 1,
            ]);

            Step::create([
                'class' => RunAutomaticBacktestStep::class,
                'queue' => 'indicators',
                'relatable_type' => ExchangeSymbol::class,
                'relatable_id' => $this->exchangeSymbol->id,
                'arguments' => [
                    'exchangeSymbolId' => $this->exchangeSymbol->id,
                    'accountId' => $this->account->id,
                    'apply' => $this->apply,
                ],
                'block_uuid' => $childBlockUuid,
                'index' => 2,
            ]);
        });

        return [
            'pair' => mb_strtoupper($this->exchangeSymbol->token.$this->exchangeSymbol->quote),
            'steps_created' => 2,
            'chain_created' => $chainCreated,
            'apply' => $this->apply,
        ];
    }
}
