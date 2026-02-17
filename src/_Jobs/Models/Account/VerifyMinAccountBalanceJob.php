<?php

declare(strict_types=1);

namespace Kraite\Core\_Jobs\Models\Account;

use Kraite\Core\Abstracts\BaseApiableJob;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSnapshot;
use Kraite\Core\Models\ApiSystem;

/*
 * VerifyMinAccountBalanceJob
 *
 * • Queries the exchange for account balance and stores in api_snapshots.
 * • Compares available-balance against the trade configuration's min_account_balance.
 * • Stops the workflow gracefully if balance is insufficient.
 */
final class VerifyMinAccountBalanceJob extends BaseApiableJob
{
    public Account $account;

    public ApiSystem $apiSystem;

    public bool $hasMinBalance = false;

    public function __construct(int $accountId)
    {
        $this->account = Account::findOrFail($accountId);
        $this->apiSystem = $this->account->apiSystem;
    }

    public function assignExceptionHandler(): void
    {
        $canonical = $this->apiSystem->canonical;
        $this->exceptionHandler = BaseExceptionHandler::make($canonical)
            ->withAccount($this->account);
    }

    public function relatable()
    {
        return $this->account;
    }

    public function computeApiable()
    {
        // Query balance from exchange and store in api_snapshots
        $apiResponse = $this->account->apiQueryBalance();
        $balanceData = $apiResponse->result;

        ApiSnapshot::storeFor($this->account, 'account-balance', $balanceData);

        // Verify minimum balance
        $availableBalance = $balanceData['available-balance'] ?? '0';
        $minAccountBalance = $this->account->tradeConfiguration->min_account_balance ?? '100';

        // Compare using bccomp for precision (returns -1 if available < min)
        $this->hasMinBalance = bccomp($availableBalance, $minAccountBalance, scale: 8) >= 0;

        return [
            'account_id' => $this->account->id,
            'balance' => $balanceData,
            'available_balance' => $availableBalance,
            'min_account_balance' => $minAccountBalance,
            'has_min_balance' => $this->hasMinBalance,
        ];
    }

    /**
     * Stop the workflow gracefully if balance is below minimum.
     */
    public function complete(): void
    {
        if (! $this->hasMinBalance) {
            $this->stopJob();
        }
    }
}
