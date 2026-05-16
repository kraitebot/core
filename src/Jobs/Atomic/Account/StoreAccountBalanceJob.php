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
final class StoreAccountBalanceJob extends BaseApiableJob
{
    public Account $account;

    public ApiSystem $apiSystem;

    public function __construct(int $accountId)
    {
        $this->account = Account::findOrFail($accountId);
        $this->apiSystem = $this->account->apiSystem;
    }

    /**
     * @param  array<string, mixed>  $balanceData
     * @return array{
     *     total_wallet_balance: float,
     *     total_unrealized_profit: float,
     *     total_maintenance_margin: float,
     *     total_margin_balance: float,
     * }
     */
    public static function historyValuesFromBalanceResult(array $balanceData): array
    {
        $totalWalletBalance = self::castToFloat(
            $balanceData['total-wallet-balance']
                ?? $balanceData['wallet-balance']
                ?? 0
        );

        $marginBalance = self::castToFloat(
            $balanceData['cross-wallet-balance']
                ?? $balanceData['total-wallet-balance']
                ?? $balanceData['wallet-balance']
                ?? 0
        );

        return [
            'total_wallet_balance' => $totalWalletBalance,
            'total_unrealized_profit' => self::castToFloat($balanceData['cross-unrealized-pnl'] ?? 0),
            'total_maintenance_margin' => self::castToFloat($balanceData['maintenance-margin'] ?? 0),
            'total_margin_balance' => $marginBalance,
        ];
    }

    public static function castToFloat(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
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
        $apiResponse = $this->account->apiQueryBalance();
        $historyValues = self::historyValuesFromBalanceResult($apiResponse->result);

        DB::transaction(function () use ($historyValues) {
            AccountBalanceHistory::create([
                'account_id' => $this->account->id,
                ...$historyValues,
            ]);
        });

        return [
            'account_id' => $this->account->id,
            ...$historyValues,
        ];
    }
}
