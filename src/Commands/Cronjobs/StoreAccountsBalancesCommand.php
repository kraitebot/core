<?php

declare(strict_types=1);

namespace Kraite\Core\Commands\Cronjobs;

use Illuminate\Support\Facades\DB;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\AccountBalanceHistory;
use StepDispatcher\Support\BaseCommand;
use Throwable;

final class StoreAccountsBalancesCommand extends BaseCommand
{
    /**
     * Run with:
     *  php artisan ingestion:store-accounts-balances
     */
    protected $signature = 'cronjobs:store-accounts-balances
                            {--clean : Truncate tables and clear laravel.log}
                            {--output : Display command output (silent by default)}';

    /**
     * Stores accounts balances for each active account.
     *
     * Logic:
     *  - Query balances per active account
     *  - Insert a snapshot row
     *  - If |unrealized PnL| > 10% of total wallet balance, send a user notification.
     */
    protected $description = 'Stores accounts balances for each active account';

    public function handle(): int
    {
        // Handle the --clean flag logic
        if ($this->option('clean')) {
            $this->verboseInfo('Truncating steps, account_balance_history, api_request_logs tables...');

            DB::statement('SET FOREIGN_KEY_CHECKS=0;');

            DB::table('steps')->truncate();
            DB::table('account_balance_history')->truncate();
            DB::table('api_request_logs')->truncate();
            DB::table('notification_logs')->truncate();

            DB::statement('SET FOREIGN_KEY_CHECKS=1;');

            $this->verboseInfo('✓ Tables truncated');

            cleanLogsFolder();
            $this->verboseInfo('✓ All logs and log directories cleared');

            $this->verboseNewLine();
        }

        // Retrieve all active accounts
        $accounts = Account::active()->get();

        foreach ($accounts as $account) {
            try {
                // Query current account information (includes wallet balance, unrealized profit, etc.)
                /** @var array<string, mixed> $accountData */
                $accountData = $account->apiQuery()->result;

                // Normalize numeric fields for safe arithmetic / persistence
                $walletBalanceRaw = $accountData['totalWalletBalance'] ?? 0;
                $newWalletBalance = (float) (is_numeric($walletBalanceRaw) ? $walletBalanceRaw : 0);

                $unrealizedProfitRaw = $accountData['totalUnrealizedProfit'] ?? 0;
                $newUnrealizedProfit = (float) (is_numeric($unrealizedProfitRaw) ? $unrealizedProfitRaw : 0);

                $maintMarginRaw = $accountData['totalMaintMargin'] ?? 0;
                $newMaintMargin = (float) (is_numeric($maintMarginRaw) ? $maintMarginRaw : 0);

                $marginBalanceRaw = $accountData['totalMarginBalance'] ?? 0;
                $newMarginBalance = (float) (is_numeric($marginBalanceRaw) ? $marginBalanceRaw : 0);

                // Persist the new snapshot
                DB::transaction(static function () use ($account, $newWalletBalance, $newUnrealizedProfit, $newMaintMargin, $newMarginBalance) {
                    AccountBalanceHistory::create([
                        'account_id' => $account->id,
                        'total_wallet_balance' => $newWalletBalance,
                        'total_unrealized_profit' => $newUnrealizedProfit,
                        'total_maintenance_margin' => $newMaintMargin,
                        'total_margin_balance' => $newMarginBalance,
                    ]);
                });
            } catch (Throwable $e) {
                $this->verboseError("Failed to query balance for account {$account->id}: {$e->getMessage()}");

                continue;
            }
        }

        return self::SUCCESS;
    }
}
