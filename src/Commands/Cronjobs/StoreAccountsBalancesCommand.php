<?php

declare(strict_types=1);

namespace Kraite\Core\Commands\Cronjobs;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kraite\Core\Jobs\Lifecycles\Account\DispatchAccountBalancesJob;
use StepDispatcher\Models\Step;
use StepDispatcher\Support\BaseCommand;

final class StoreAccountsBalancesCommand extends BaseCommand
{
    protected $signature = 'kraite:cron-store-accounts-balances
                            {--clean : Truncate tables and clear laravel.log}
                            {--output : Display command output (silent by default)}';

    protected $description = 'Stores accounts balances for each active account.';

    public function handle(): int
    {
        if ($this->option('clean')) {
            $this->cleanTables();
        }

        $this->dispatchJob();

        return self::SUCCESS;
    }

    private function cleanTables(): void
    {
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

    private function dispatchJob(): void
    {
        Step::create([
            'class' => DispatchAccountBalancesJob::class,
            'child_block_uuid' => (string) Str::uuid(),
        ]);

        $this->verboseComment('→ Dispatched DispatchAccountBalancesJob');
    }
}
