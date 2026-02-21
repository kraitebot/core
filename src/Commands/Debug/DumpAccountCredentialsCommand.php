<?php

declare(strict_types=1);

namespace Kraite\Core\Commands\Debug;

use Kraite\Core\Models\Account;
use StepDispatcher\Support\BaseCommand;

final class DumpAccountCredentialsCommand extends BaseCommand
{
    /**
     * Run with:
     *  php artisan debug:dump-account-credentials {account_id}
     */
    protected $signature = 'debug:dump-account-credentials
                            {account_id : The account ID to dump credentials for}
                            {--output : Display command output (silent by default)}';

    /**
     * Dumps the unencrypted API credentials for a specific account.
     *
     * ⚠️ WARNING: This command displays sensitive credentials in plaintext.
     * Only use in secure environments and never log the output.
     */
    protected $description = 'Dump unencrypted API credentials for a specific account (USE WITH CAUTION)';

    public function handle(): int
    {
        $accountId = (int) $this->argument('account_id');

        $account = Account::find($accountId);

        if (! $account) {
            $this->verboseError("❌ Account ID {$accountId} not found");

            return self::FAILURE;
        }

        $this->verboseWarn('⚠️  WARNING: Displaying sensitive credentials in plaintext!');
        $this->verboseNewLine();

        $this->verboseLine('═══════════════════════════════════════════════════════════');
        $this->verboseInfo("Account ID: {$account->id}");
        $this->verboseInfo("Name: {$account->name}");
        $this->verboseInfo("API System: {$account->apiSystem->name} (ID: {$account->api_system_id})");
        $this->verboseLine('═══════════════════════════════════════════════════════════');
        $this->verboseNewLine();

        // Binance credentials
        if (! empty($account->binance_api_key) || ! empty($account->binance_api_secret)) {
            $this->verboseLine('<fg=yellow>BINANCE CREDENTIALS:</>');
            $this->verboseLine('-----------------------------------------------------------');
            $this->verboseLine('API Key:    '.($account->binance_api_key ?: '<empty>'));
            $this->verboseLine('API Secret: '.($account->binance_api_secret ?: '<empty>'));
            $this->verboseNewLine();
        }

        // Bybit credentials
        if (! empty($account->bybit_api_key) || ! empty($account->bybit_api_secret)) {
            $this->verboseLine('<fg=cyan>BYBIT CREDENTIALS:</>');
            $this->verboseLine('-----------------------------------------------------------');
            $this->verboseLine('API Key:    '.($account->bybit_api_key ?: '<empty>'));
            $this->verboseLine('API Secret: '.($account->bybit_api_secret ?: '<empty>'));
            $this->verboseNewLine();
        }

        // Check if no credentials found
        if (empty($account->binance_api_key) && empty($account->binance_api_secret) &&
            empty($account->bybit_api_key) && empty($account->bybit_api_secret)) {
            $this->verboseWarn('⚠️  No credentials found for this account');
        }

        $this->verboseLine('═══════════════════════════════════════════════════════════');
        $this->verboseNewLine();
        $this->verboseComment('💡 Tip: Verify these credentials match your exchange API settings');
        $this->verboseComment('💡 Check: IP whitelist + API permissions (Enable Reading, Enable Futures)');

        return self::SUCCESS;
    }
}
