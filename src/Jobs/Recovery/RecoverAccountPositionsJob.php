<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Recovery;

use Kraite\Core\Abstracts\BaseApiableJob;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Support\Recovery\AccountRecoveryRunner;
use Kraite\Core\Support\Recovery\RecoveryApiThrottle;
use Kraite\Core\Support\Recovery\RecoveryReport;
use Throwable;

/**
 * RecoverAccountPositionsJob (Atomic, fleet-distributed)
 *
 * Rebuilds ONE account's open positions + orders from the exchange. Fanned
 * out one-per-account by `RecoverPositionsCommand` in fan-out mode; the
 * StepRouter routes each to a worker whose IP is clean for that account's
 * exchange, so recovery parallelises across the fleet and each box works
 * within its own per-IP rate budget.
 *
 * Extends `BaseApiableJob` for the entry throttle gate + exchange
 * exception/backoff handling; the recoverer's own calls throttle per-call
 * via `RecoveryApiThrottle`. Idempotent — safe to re-dispatch a failed
 * account's step; already-recovered rows are left untouched.
 */
final class RecoverAccountPositionsJob extends BaseApiableJob
{
    public Account $account;

    public ApiSystem $apiSystem;

    private ?string $tokenFilter;

    private bool $allowUntestedExchange;

    public function __construct(int $accountId, ?string $tokenFilter = null, bool $allowUntestedExchange = false)
    {
        $this->account = Account::findOrFail($accountId);
        $this->apiSystem = $this->account->apiSystem;
        $this->tokenFilter = $tokenFilter;
        $this->allowUntestedExchange = $allowUntestedExchange;
    }

    public function assignExceptionHandler(): void
    {
        $this->exceptionHandler = BaseExceptionHandler::make($this->apiSystem->canonical)
            ->withAccount($this->account);
    }

    public function relatable()
    {
        return $this->account;
    }

    public function computeApiable()
    {
        $report = new RecoveryReport;

        [$openOrders, $algoOrders] = $this->prefetchOrderBatches();

        (new AccountRecoveryRunner(
            account: $this->account,
            report: $report,
            tokenFilter: $this->tokenFilter,
            allowUntestedExchange: $this->allowUntestedExchange,
            batchedOpenOrders: $openOrders,
            batchedAlgoOrders: $algoOrders,
        ))->run();

        // The step's result JSON is the per-account checkpoint the command
        // aggregates once the whole fan-out settles.
        return [
            'account_id' => $this->account->id,
            'accounts_ok' => $report->accountsOk,
            'accounts_skipped' => $report->accountsSkipped,
            'positions_created' => $report->positionsCreated,
            'positions_updated' => $report->positionsUpdated,
            'positions_skipped' => $report->positionsSkipped,
            'orders_created' => $report->ordersCreated,
            'orders_skipped' => $report->ordersSkipped,
            'warnings' => $report->warnings,
        ];
    }

    /**
     * Pre-fetch account-wide open + algo orders ONCE so the recoverer
     * filters them per symbol in memory (2 calls/account instead of 2 per
     * position). A fetch failure degrades to null → the recoverer falls
     * back to its per-symbol calls, so the account still recovers.
     *
     * @return array{0: array<int, array<string, mixed>>|null, 1: array<int, array<string, mixed>>|null}
     */
    private function prefetchOrderBatches(): array
    {
        $stepId = $this->step->id;

        try {
            $open = RecoveryApiThrottle::call($this->account, fn () => $this->account->apiQueryOpenOrders(), $stepId)->result ?? [];
        } catch (Throwable) {
            $open = null;
        }

        try {
            $algo = RecoveryApiThrottle::call(
                $this->account,
                fn () => match ($this->account->apiSystem->canonical) {
                    'bitget' => $this->account->apiQueryPlanOrders(),
                    default => $this->account->apiQueryAlgoOrders(),
                },
                $stepId,
            )->result ?? [];
        } catch (Throwable) {
            $algo = null;
        }

        return [$open, $algo];
    }
}
