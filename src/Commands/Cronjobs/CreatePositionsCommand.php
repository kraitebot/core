<?php

declare(strict_types=1);

namespace Kraite\Core\Commands\Cronjobs;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Kraite\Core\Jobs\Lifecycles\Account\PreparePositionsOpeningJob;
use Kraite\Core\Jobs\Lifecycles\Position\DispatchPositionJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\Proxies\JobProxy;
use Kraite\Core\Trading\Kraite;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Dispatched;
use StepDispatcher\States\Pending;
use StepDispatcher\States\Running;
use StepDispatcher\Support\BaseCommand;
use StepDispatcher\Support\Steps;
use Throwable;

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
            // Environment gate — `--clean` truncates 8 tables (orders,
            // positions, steps, api logs, snapshots, audit logs) and wipes
            // log directories. Running it on production destroys the local
            // source of truth while exchange positions and orders may still
            // be live, leaving the system unable to reconcile. The gate
            // refuses any non-local/non-testing environment outright; if a
            // production reset is ever genuinely needed, that should be a
            // separate, explicitly-named, audit-logged command — not a
            // flag on the every-tick opening cron.
            if (! app()->environment(['local', 'testing'])) {
                $this->error(sprintf(
                    '[CREATE-POSITIONS] --clean refused: current environment is "%s". This flag only runs in local or testing.',
                    app()->environment()
                ));

                return self::FAILURE;
            }

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

        // Single eager-loaded query for every account whose owner is
        // also `can_trade=true`. Avoids the N+1 shape where the legacy
        // outer-then-inner loop re-queried `user->accounts()` per user.
        // Account count is the natural unit of work for this cron, so
        // we drive the iteration off the account collection directly.
        //
        // Eager-loads `user.subscription` because the per-account
        // readiness check (`Account::isReadyToTrade`) consults
        // `$user->billing()->subscription()->isActive()`, which reads
        // `monthly_rate_usdt` on the tier.
        $accounts = Account::query()
            ->with(['apiSystem', 'user.subscription'])
            ->where('is_active', true)
            ->where('can_trade', true)
            ->whereHas('user', static fn ($q) => $q->where('can_trade', true))
            ->get()
            // Shuffle dispatch order so all accounts on the same exchange
            // don't fire their balance/positions/orders prep queries in
            // deterministic lockstep through the same throttler bucket.
            // Without this, every cooldown-recovery tick produces a
            // synchronized burst — the throttler rescheduleWithoutRetry's
            // them, but the burst still pressures the API. Per-account
            // first-call timing is now spread across the loop.
            ->shuffle();

        $this->verboseInfo("Found {$accounts->count()} tradeable account(s).");

        // Both the orphan-recovery dedupe SELECT and every
        // PreparePositionsOpeningJob INSERT must hit `trading_steps`.
        // The dispatch chain that follows (Prepare → Verify → Query →
        // Determine → Set → DispatchPosition → ...) inherits the
        // ambient prefix on every worker via BaseStepJob::handle(),
        // so the entire opening lifecycle stays inside the trading
        // table set end-to-end.
        Steps::usingPrefix('trading', function () use ($accounts): void {
            foreach ($accounts as $account) {
                // Per-account try/catch — keeps one account's failure (a
                // hung API client throwing inside the orphan check, a DB
                // deadlock on the open-positions count, a malformed
                // exchange_symbol relation, anything) from killing the
                // entire cron tick. Without this guard, account #47
                // throwing meant accounts 48..N skipped the rest of this
                // 3-minute cycle. With 200+ accounts on the box, that
                // blast radius is unacceptable.
                try {
                    // 3-gate per-account readiness: account.is_active +
                    // account.can_trade (set 1) + user.can_trade (set 2)
                    // + user.subscription.isActive() (set 3 — trial OR
                    // wallet covers next renewal). The query already
                    // covered sets 1 & 2 above; this re-check picks up
                    // set 3 AND survives any account/user state that
                    // shifted between the SELECT and the loop.
                    if (! $account->isReadyToTrade()) {
                        $this->verboseComment("    → Account #{$account->id} not ready to trade (subscription gate), skipping");

                        continue;
                    }

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
                } catch (Throwable $e) {
                    Log::channel('jobs')->error('[CREATE-POSITIONS] per-account iteration threw — continuing with the rest of the accounts', [
                        'account_id' => $account->id,
                        'account_name' => $account->name,
                        'exception' => $e::class,
                        'message' => $e->getMessage(),
                    ]);

                    $exceptionClass = $e::class;
                    $this->verboseWarn("    → Account #{$account->id} threw {$exceptionClass} — skipped, rest of accounts continue.");
                }
            }
        });

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
            // Indexed orphan lookup. The legacy
            // `whereJsonContains('arguments->positionId', …)` predicate
            // is unindexed and degrades to a near-full scan of the
            // steps table at scale. The indexed
            // (`relatable_type`, `relatable_id`, `state`) tuple is
            // covered by `idx_p_steps_rel_state_idx`.
            //
            // The OR-fallback against the JSON path stays for the
            // brief transition window where pre-existing Pending steps
            // (created before this change) may not yet have their
            // `relatable_*` columns populated — those columns are set
            // by the framework when the worker picks the step up. The
            // window closes as soon as the dispatcher promotes any
            // such Pending row to Dispatched / Running.
            $hasLiveStep = Step::query()
                ->whereIn('class', $candidateClasses)
                ->whereNotIn('state', $terminalStates)
                ->where(static function ($query) use ($position) {
                    $query->where(static function ($qq) use ($position) {
                        $qq->where('relatable_type', Position::class)
                            ->where('relatable_id', $position->id);
                    })->orWhereJsonContains('arguments->positionId', $position->id);
                })
                ->exists();

            if ($hasLiveStep) {
                continue;
            }

            // Populate `relatable_*` at create time so the next tick's
            // orphan check resolves it via the indexed path even while
            // the step is still Pending.
            Step::create([
                'class' => $exchangeDispatchClass,
                'queue' => 'positions',
                'relatable_type' => Position::class,
                'relatable_id' => $position->id,
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

        // Dedupe: skip if a PreparePositionsOpeningJob is already
        // pending/dispatched/running for this account. Pre-fix, every
        // 3-minute cron tick would stack another opening workflow
        // even if the prior one was still throttled or queue-delayed —
        // duplicating expensive balance/open-positions/open-orders API
        // checks and adding pressure on the throttler that the
        // duplicate workflow can't pass anyway.
        $alreadyPending = Step::query()
            ->where('class', PreparePositionsOpeningJob::class)
            ->whereRaw("CAST(JSON_EXTRACT(arguments, '$.accountId') AS UNSIGNED) = ?", [$account->id])
            ->whereIn('state', [Pending::class, Dispatched::class, Running::class])
            ->exists();

        if ($alreadyPending) {
            $this->verboseComment('    → Skipped: PreparePositionsOpeningJob already pending for this account');

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
