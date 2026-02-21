<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\Account;

use Exception;
use Kraite\Core\Abstracts\BaseApiableJob;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSnapshot;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Support\Math;

/**
 * VerifyMinAccountBalanceJob (Atomic)
 *
 * Queries the exchange for account balance and stores in api_snapshots.
 * Compares available-balance against the trade configuration's min_account_balance.
 * Throws exception to stop the workflow gracefully if balance is insufficient.
 */
final class VerifyMinAccountBalanceJob extends BaseApiableJob
{
    public Account $account;

    public ApiSystem $apiSystem;

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

    public function relatable(): Account
    {
        return $this->account;
    }

    public function computeApiable(): null
    {
        // Query balance from exchange and store in api_snapshots
        $apiResponse = $this->account->apiQueryBalance();
        $balanceData = $apiResponse->result;

        ApiSnapshot::storeFor($this->account, 'account-balance', $balanceData);

        // Extract balance values
        $availableBalance = $balanceData['available-balance'] ?? '0';
        $minAccountBalance = $this->account->tradeConfiguration->min_account_balance ?? '100';

        // Verify minimum balance
        $hasMinBalance = Math::gte($availableBalance, $minAccountBalance, 8);

        // Store result before potential exception
        $this->step->update([
            'response' => [
                'account_id' => $this->account->id,
                'balance' => $balanceData,
                'available_balance' => $availableBalance,
                'min_account_balance' => $minAccountBalance,
                'has_min_balance' => $hasMinBalance,
            ],
        ]);

        // Insufficient balance - throw to stop workflow gracefully
        if (! $hasMinBalance) {
            throw new Exception(
                "Insufficient balance: {$availableBalance} < {$minAccountBalance} (minimum required)"
            );
        }

        return null;
    }
}
