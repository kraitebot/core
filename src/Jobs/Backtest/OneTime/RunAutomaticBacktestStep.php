<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Backtest\OneTime;

use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Support\Backtest\OneTime\AutomaticBacktestEvaluator;

final class RunAutomaticBacktestStep extends BaseQueueableJob
{
    public ExchangeSymbol $exchangeSymbol;

    public Account $account;

    public function __construct(int $exchangeSymbolId, int $accountId, public bool $apply = false)
    {
        $this->exchangeSymbol = ExchangeSymbol::findOrFail($exchangeSymbolId);
        $this->account = Account::findOrFail($accountId);
        $this->retries = 3;
    }

    public function relatable(): ExchangeSymbol
    {
        return $this->exchangeSymbol;
    }

    /**
     * @return array<string, mixed>
     */
    public function compute(): array
    {
        return app(AutomaticBacktestEvaluator::class)->evaluate(
            $this->exchangeSymbol,
            $this->account,
            $this->apply,
        );
    }
}
