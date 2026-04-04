<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\Account;

use Illuminate\Support\Facades\DB;
use Kraite\Core\Abstracts\BaseApiableJob;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\AccountBalanceHistory;
use Kraite\Core\Models\ApiSystem;

/**
 * StoreAccountBalanceJob (Atomic)
 *
 * Queries the exchange for the current account balance and stores
 * a snapshot in the account_balance_history table.
 */
class StoreAccountBalanceJob extends BaseApiableJob
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

    public function relatable()
    {
        return $this->account;
    }

    public function computeApiable(): array
    {
        $apiResponse = $this->account->apiQuery();
        $accountData = $apiResponse->result;

        $walletBalance = $this->castToFloat($accountData['totalWalletBalance'] ?? 0);
        $unrealizedProfit = $this->castToFloat($accountData['totalUnrealizedProfit'] ?? 0);
        $maintMargin = $this->castToFloat($accountData['totalMaintMargin'] ?? 0);
        $marginBalance = $this->castToFloat($accountData['totalMarginBalance'] ?? 0);

        DB::transaction(function () use ($walletBalance, $unrealizedProfit, $maintMargin, $marginBalance) {
            AccountBalanceHistory::create([
                'account_id' => $this->account->id,
                'total_wallet_balance' => $walletBalance,
                'total_unrealized_profit' => $unrealizedProfit,
                'total_maintenance_margin' => $maintMargin,
                'total_margin_balance' => $marginBalance,
            ]);
        });

        return [
            'account_id' => $this->account->id,
            'total_wallet_balance' => $walletBalance,
            'total_margin_balance' => $marginBalance,
        ];
    }

    public function castToFloat(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}
