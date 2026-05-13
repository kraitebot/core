<?php

declare(strict_types=1);

namespace Kraite\Core\Commands\Cronjobs;

use Illuminate\Support\Facades\DB;
use Kraite\Core\Jobs\Lifecycles\Account\DispatchAccountBalancesJob;
use StepDispatcher\Models\Step;
use StepDispatcher\Support\BaseCommand;

final class StoreAccountsBalancesCommand extends BaseCommand
{
    protected $signature = 'kraite:cron-store-accounts-balances
                            {--clean : Truncate operational + audit tables and clear logs (DESTRUCTIVE — requires --force outside local)}
                            {--force : Required to run --clean outside the `local` environment}
                            {--output : Display command output (silent by default)}';

    protected $description = 'Stores accounts balances for each active account.';

    public function handle(): int
    {
        if ($this->option('clean')) {
            // --clean truncates `steps`, `account_balance_history`,
            // `api_request_logs`, `notification_logs`, plus log files.
            // In production that erases dispatcher history, balance
            // history, API evidence, notifications, and cleanup logs —
            // exactly the data needed for incident reconstruction.
            // Refuse outside `local` unless --force is supplied so a
            // mistyped command can't silently destroy audit trails on
            // a live host.
            if (app()->environment() !== 'local' && ! (bool) $this->option('force')) {
                $this->error("--clean refused: '".app()->environment()."' is not 'local'. This truncates dispatcher + balance + API + notification audit history. Pass --force only if you intentionally want to erase those tables on this host.");

                return self::FAILURE;
            }

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
        // Orchestrator self-elects to parent mode inside compute() via
        // $this->step->makeItAParent() — only when at least one account is
        // active and gets a child step. Pre-setting here would zombie the
        // step on the no-active-accounts edge case.
        Step::create([
            'class' => DispatchAccountBalancesJob::class,
            'queue' => 'cronjobs',
        ]);

        $this->verboseComment('→ Dispatched DispatchAccountBalancesJob');
    }
}
