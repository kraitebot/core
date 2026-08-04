<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\Position;

use Kraite\Core\Abstracts\BaseApiableJob;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\PositionClosedNotifier;
use RuntimeException;

/**
 * FetchAccountPositionsPnlJob (Atomic)
 *
 * Base class for fetching exchange-reported PnL for closed positions.
 * Exchange-specific overrides implement the actual API call and matching logic.
 *
 * Overrides: Jobs/Atomic/Position/{Exchange}/FetchAccountPositionsPnlJob.php
 */
class FetchAccountPositionsPnlJob extends BaseApiableJob
{
    /** @var Account */
    public $account;

    public function __construct(int $accountId)
    {
        $this->account = Account::findOrFail($accountId);
    }

    public function assignExceptionHandler(): void
    {
        $canonical = $this->account->apiSystem->canonical;
        $this->exceptionHandler = BaseExceptionHandler::make($canonical)
            ->withAccount($this->account);
    }

    public function relatable()
    {
        return $this->account;
    }

    public function computeApiable(): array
    {
        throw new RuntimeException(
            'FetchAccountPositionsPnlJob must be overridden by exchange-specific implementation. '
            .'Exchange: '.$this->account->apiSystem->canonical
        );
    }

    protected function persistPnl(Position $position, string $pnl): void
    {
        $position->updateSaving(['pnl' => $pnl]);

        app(PositionClosedNotifier::class)->send($position->refresh());
    }
}
