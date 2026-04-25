<?php

declare(strict_types=1);

namespace Kraite\Core\Commands\Cronjobs;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Kraite\Core\Jobs\Lifecycles\Account\PreparePositionsOpeningJob;
use Kraite\Core\Jobs\Lifecycles\Position\DispatchPositionJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\User;
use Kraite\Core\Support\Proxies\JobProxy;
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
    protected $signature = 'kraite:cron-create-positions
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
            /** @var Collection<int, Account> $accounts */
            $accounts = $user->accounts()
                ->with('apiSystem')
                ->where('is_active', true)
                ->where('can_trade', true)
                ->get();

            $this->verboseComment("User #{$user->id}: {$accounts->count()} tradeable account(s)");

            foreach ($accounts as $account) {
                // Self-heal first — orphan 'new' positions (their previous
                // DispatchPositionJob step was swept during operator
                // cleanup, supervisor restart, or any cleanup that lost a
                // step row while leaving the position behind) get re-
                // dispatched before we contemplate opening new slots.
                // Runs unconditionally — recovering a stranded orphan
                // doesn't compete for slot capacity, the slot is already
                // taken.
                $this->recoverOrphanPositionsForAccount($account);

                $this->attemptOpeningPositionsForAccount($account);
            }
        }

        return self::SUCCESS;
    }

    /**
     * Find positions in 'new' status with a token assigned but no live
     * DispatchPositionJob step, and re-dispatch them. Position 235 (and
     * 233) hit this on 2026-04-25 — manual cleanup deleted their follow-
     * up DispatchPositionJob alongside genuinely-stale Pending rows,
     * leaving the positions stranded. This recovery makes that operator
     * mistake transparent: next tick picks them up.
     */
    private function recoverOrphanPositionsForAccount(Account $account): void
    {
        $orphanPositions = $account->positions()
            ->where('status', 'new')
            ->whereNotNull('exchange_symbol_id')
            ->get();

        if ($orphanPositions->isEmpty()) {
            return;
        }

        $resolver = JobProxy::with($account);
        $exchangeDispatchClass = $resolver->resolve(DispatchPositionJob::class);

        // The two classes that can carry the orphan — base or per-exchange
        // override. Either one with a non-terminal state means the position
        // is being processed by an active workflow.
        $candidateClasses = array_unique([DispatchPositionJob::class, $exchangeDispatchClass]);

        $terminalStates = Step::terminalStepStates();

        $recovered = 0;

        foreach ($orphanPositions as $position) {
            $hasLiveStep = Step::query()
                ->whereIn('class', $candidateClasses)
                ->whereJsonContains('arguments->positionId', $position->id)
                ->whereNotIn('state', $terminalStates)
                ->exists();

            if ($hasLiveStep) {
                continue;
            }

            Step::create([
                'class' => $exchangeDispatchClass,
                'queue' => 'positions',
                'arguments' => ['positionId' => $position->id],
            ]);

            $recovered++;
        }

        if ($recovered > 0) {
            $this->verboseInfo("    → Recovered {$recovered} orphan position(s) for account #{$account->id}");
        }
    }

    /**
     * Attempt to open positions for an account if guards pass.
     */
    private function attemptOpeningPositionsForAccount(Account $account): void
    {
        // Idempotency guard at the command entry. The step-dispatcher
        // framework guarantees ordering WITHIN a workflow tree, but if
        // two PreparePositionsOpeningJob root steps are enqueued for the
        // same account they're independent trees — both run their full
        // Verify/Query/Assign/Dispatch chain in parallel, producing twin
        // DispatchPositionJob blocks per position. The 2026-04-25 17:33
        // cluster (12 Failed steps, realised loss on positions #241 +
        // #242) was caused by exactly that — two PreparePositionsOpening
        // dispatched 1s apart (operator manual artisan invocation
        // racing the scheduled cron, schedule:work lag batching missed
        // ticks, or a stale withoutOverlapping mutex releasing two
        // pending ticks back-to-back). withoutOverlapping() protects
        // scheduler-against-scheduler but cannot stop ANY of those.
        // Skip the dispatch when an opening workflow is already in
        // flight; the next cron tick (3 min later) will pick up where
        // we left off.
        $alreadyInFlight = Step::query()
            ->where('class', PreparePositionsOpeningJob::class)
            ->whereJsonContains('arguments->accountId', $account->id)
            ->whereNotIn('state', Step::terminalStepStates())
            ->exists();

        if ($alreadyInFlight) {
            $this->verboseComment('    → PreparePositionsOpeningJob already in flight for this account, skipping');

            return;
        }

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

        // Dispatch workflow to cross-check with exchange and open positions.
        // The orchestrator self-elects to parent mode inside its compute()
        // via $this->step->makeItAParent() — pre-setting child_block_uuid
        // here would commit the step to parent-mode before compute() can
        // decide, which is the zombie pattern documented in
        // ~/steps-dispatcher/issue.md.
        Step::create([
            'class' => PreparePositionsOpeningJob::class,
            'queue' => 'cronjobs',
            'arguments' => [
                'accountId' => $account->id,
            ],
        ]);

        $this->verboseComment('    → Dispatched PreparePositionsOpeningJob');
    }
}
