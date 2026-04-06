<?php

declare(strict_types=1);

namespace Kraite\Core\Commands\Cronjobs;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kraite\Core\Jobs\Lifecycles\Account\PreparePositionsOpeningJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\User;
use Kraite\Core\Trading\Kraite;
use StepDispatcher\Models\Step;
use StepDispatcher\Support\BaseCommand;

final class CreatePositionsCommand extends BaseCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cronjobs:create-positions
                            {--clean : Truncate positions, orders, and related tables before running}
                            {--output : Display command output (silent by default)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Creates new trading positions based on market conditions and available slots.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if ($this->option('clean')) {
            $this->verboseInfo('Truncating positions, orders, steps, and related tables...');

            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
            DB::table('orders')->truncate();
            DB::table('positions')->truncate();
            DB::table('steps')->truncate();
            DB::table('steps_dispatcher_ticks')->truncate();
            DB::table('api_request_logs')->truncate();
            DB::table('api_snapshots')->truncate();
            DB::table('notification_logs')->truncate();
            DB::table('model_logs')->truncate();
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');

            $this->verboseInfo('✓ Tables truncated');

            cleanLogsFolder();
            $this->verboseInfo('✓ All logs and log directories cleared');

            $this->verboseNewLine();
        }

        $users = User::where('can_trade', true)->get();

        $this->verboseInfo("Found {$users->count()} user(s) with can_trade=true");

        foreach ($users as $user) {
            /** @var \Illuminate\Database\Eloquent\Collection<int, Account> $accounts */
            $accounts = $user->accounts()
                ->with('apiSystem')
                ->where('is_active', true)
                ->where('can_trade', true)
                ->get();

            $this->verboseComment("User #{$user->id}: {$accounts->count()} tradeable account(s)");

            foreach ($accounts as $account) {
                $this->attemptOpeningPositionsForAccount($account);
            }
        }

        return self::SUCCESS;
    }

    /**
     * Attempt to open positions for an account if guards pass.
     */
    private function attemptOpeningPositionsForAccount(Account $account): void
    {
        $maxSlots = $account->maxPositionSlots();
        /** @var int $openPositions */
        $openPositions = $account->positions()->opened()->count();

        $this->verboseInfo("  Account #{$account->id} ({$account->name}): {$openPositions}/{$maxSlots} positions open");

        $engine = Kraite::withAccount($account);

        // Global guard with circuit breaker
        if (! $engine->canOpenPositions()) {
            $this->verboseComment('    → Global guard prevents opening, skipping');

            return;
        }

        // Exchange cooldown guard — skip if the exchange is reporting instability
        if ($account->apiSystem->inCooldown()) {
            $this->verboseComment("    → Exchange {$account->apiSystem->canonical} in cooldown until {$account->apiSystem->cooldown_until}, skipping");

            return;
        }

        // Check if there's at least one slot available (DB check only - cheap)
        if ($openPositions >= $maxSlots) {
            $this->verboseComment('    → No available slots, skipping');

            return;
        }

        // Check directional guards
        if (! $engine->canOpenLongs() && ! $engine->canOpenShorts()) {
            $this->verboseComment('    → Directional guards prevent opening, skipping');

            return;
        }

        // Dispatch workflow to cross-check with exchange and open positions
        Step::create([
            'class' => PreparePositionsOpeningJob::class,
            'arguments' => [
                'accountId' => $account->id,
            ],
            'child_block_uuid' => (string) Str::uuid(),
        ]);

        $this->verboseComment('    → Dispatched PreparePositionsOpeningJob');
    }
}
