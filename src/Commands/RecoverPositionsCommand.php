<?php

declare(strict_types=1);

namespace Kraite\Core\Commands;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Kraite\Core\Jobs\Recovery\RecoverAccountPositionsJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\NotificationService;
use Kraite\Core\Support\Recovery\AccountRecoveryRunner;
use Kraite\Core\Support\Recovery\RecoveryReport;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Cancelled;
use StepDispatcher\States\Failed;
use StepDispatcher\States\Stopped;
use StepDispatcher\Support\BaseCommand;
use StepDispatcher\Support\StepDispatcher;
use StepDispatcher\Support\Steps;
use Throwable;

/**
 * RecoverPositionsCommand
 *
 * Reconstructs the local DB mirror of currently-open positions and their
 * order history from the exchange APIs. Idempotent — only inserts what's
 * missing (matched by exchange_order_id for orders, and by
 * account+symbol+direction+open-status for positions).
 *
 * Hardened for disaster recovery: deactivates the step dispatcher
 * before touching the DB, restores it in a finally block, runs each
 * position's reconciliation in its own transaction so a single bad
 * position can't roll back the whole run.
 *
 * Calls exchange APIs DIRECTLY (no Step orchestration) — this is an
 * exceptional, operator-driven flow that runs once after disaster.
 *
 * Examples:
 *   php artisan kraite:recover-positions                     # all accounts, all symbols
 *   php artisan kraite:recover-positions --account_id=1      # single account
 *   php artisan kraite:recover-positions --account_id=1 --token=APEUSDT  # one symbol
 *   php artisan kraite:recover-positions --dry-run           # preview only
 */
final class RecoverPositionsCommand extends BaseCommand
{
    protected $signature = 'kraite:recover-positions
                            {--account_id= : Restrict to a single account by id}
                            {--token= : Restrict to a single trading pair (e.g. APEUSDT)}
                            {--dry-run : Run end-to-end but roll back all writes at the end}
                            {--override : Delete matching local positions+orders BEFORE recovery so the rebuild is from scratch (scoped by --account_id and --token if set)}
                            {--allow-untested-exchange : Permit recoverers flagged as untested (Bybit, KuCoin) to run; they are gated off by default to prevent disaster paths from running unverified code at the worst possible time}
                            {--force : Required for unscoped --override (no --account_id, no --token). Without this gate a fleet-wide wipe would only need a single mistyped flag — explicit --force makes the destructive intent visible}
                            {--allow-snapshot-failure : Required for --override to proceed when the pre-run mysqldump snapshot fails. Default refusal protects the only restore point}
                            {--inline : Run single-box + sequential (the deepest-disaster path: full dispatcher freeze, direct execution). Default is fleet fan-out across the workers. --dry-run always forces --inline}
                            {--poll-timeout-minutes=30 : Fan-out mode: how long to wait for the per-account recovery jobs to settle before reporting a timeout}';

    protected $description = 'Reconstruct local positions + orders from the exchange APIs (disaster recovery, idempotent)';

    public function handle(): int
    {
        $accountId = $this->option('account_id');
        $tokenFilter = $this->option('token');
        $dryRun = (bool) $this->option('dry-run');
        $override = (bool) $this->option('override');

        // A distributed dry-run cannot be rolled back across many committed
        // per-account jobs, so a truthful dry-run must take the single-box
        // transactional path. --inline forces it explicitly (deepest-disaster
        // mode, or single-account targeted recovery).
        $inline = $dryRun || (bool) $this->option('inline');

        $accounts = $this->resolveTargetAccounts($accountId);
        if ($accounts->isEmpty()) {
            $this->error('No matching accounts found.');

            return self::FAILURE;
        }

        // Production-grade --override gate: an unscoped destructive
        // override (no --account_id, no --token) must carry an explicit
        // --force flag. A mistyped operator command of the form
        // `kraite:recover-positions --override` would otherwise wipe
        // every position + every order across every account in scope.
        // The dry-run path is exempt — it rolls back all writes by
        // design, so it serves as the safe preview.
        if ($override && ! $dryRun && ! $accountId && ! $tokenFilter && ! (bool) $this->option('force')) {
            $this->error('--override without --account_id or --token requires --force. The unscoped destructive override would wipe every position + every order across every account in scope. Re-run with --dry-run first to preview, then add --force to confirm intent.');

            return self::FAILURE;
        }

        $report = new RecoveryReport;
        $report->dryRun = $dryRun;

        $this->printHeader($accounts->count(), $accountId, $tokenFilter, $dryRun, $override, $inline);

        // Capture pre-run trading flag so we can restore it, and dump
        // positions + orders BEFORE any writes so a botched recovery has a
        // known restore point. Both modes freeze opening the same way; only
        // the execution (inline vs fleet fan-out) differs below.
        $tradingWasOn = $this->freezeTrading();
        $snapshotPath = $dryRun ? null : $this->snapshotDatabase($report);

        // Snapshot is the only restore point a botched destructive
        // override can fall back on. Refuse to proceed with --override
        // when the snapshot failed unless the operator explicitly opts
        // in via --allow-snapshot-failure.
        if ($override && ! $dryRun && $snapshotPath === null && ! (bool) $this->option('allow-snapshot-failure')) {
            $this->error('--override aborted: pre-run mysqldump snapshot failed and no restore point exists. Re-run with --allow-snapshot-failure if you have an alternative recovery plan.');
            $this->restoreTrading($tradingWasOn);

            return self::FAILURE;
        }

        if ($inline) {
            $result = $this->runInline($accounts, $tokenFilter, $override, $dryRun, $report);

            $this->renderReport($report);
            $this->restoreTrading($tradingWasOn);

            if ($result === self::SUCCESS && ! $dryRun) {
                $this->notifyCompletion($report, $snapshotPath);
            }

            return $result;
        }

        // Fleet fan-out.
        $result = $this->runFanOut($accounts, $tokenFilter, $override, $report);

        $this->renderReport($report);

        if ($result === self::SUCCESS) {
            $this->restoreTrading($tradingWasOn);
            $this->notifyCompletion($report, $snapshotPath);
        } else {
            // Recovery did not fully settle — leave trading frozen (a safe
            // state: no new opens) rather than restore over half-recovered
            // accounts. The per-account jobs keep running on the fleet.
            $this->warn('Recovery did not fully settle — trading stays frozen (allow_opening_positions=false). Accounts may still be recovering on the fleet. Re-run to resume, or warm up to lift the freeze once verified.');
        }

        return $result;
    }

    /**
     * Resolve the account collection: either a single account by id, or
     * every is_active account with a configured api_system. Inactive
     * accounts are excluded — recovery doesn't make sense for accounts
     * we don't otherwise touch.
     */
    protected function resolveTargetAccounts($accountId)
    {
        $query = Account::query()->with('apiSystem');

        if ($accountId !== null) {
            return $query->where('id', (int) $accountId)->get();
        }

        return $query->where('is_active', true)->get();
    }

    protected function printHeader(int $accountCount, $accountId, ?string $tokenFilter, bool $dryRun, bool $override, bool $inline): void
    {
        $this->line('');
        $this->line('======================================================================');
        $this->line(' Kraite Position Recovery');
        $this->line('======================================================================');
        $this->line(' Accounts in scope: '.$accountCount.($accountId !== null ? " (account_id={$accountId})" : ''));

        if ($tokenFilter !== null) {
            $this->line(' Token filter:      '.$tokenFilter);
        }

        $this->line(' Mode:              '.($inline ? 'INLINE (single-box, sequential)' : 'FLEET FAN-OUT (distributed across workers)'));
        $this->line(' Dry-run:           '.($dryRun ? 'YES (no writes will persist)' : 'NO'));
        $this->line(' Override:          '.($override ? 'YES (matching positions+orders will be wiped first)' : 'NO'));
        $this->line(' Dispatcher:        '.($inline ? 'deactivated for the duration of this run' : 'running (routes recovery jobs); opening frozen via flag'));
        $this->line('======================================================================');
        $this->line('');
    }

    /**
     * Delete the local positions+orders that match the current scope so
     * the recovery rebuilds from scratch instead of finding existing
     * rows and short-circuiting via the idempotent path. Scoping rules:
     *
     *   --account_id=N --token=T  → only that account's that-symbol positions
     *   --account_id=N            → all of that account's positions
     *   --token=T                 → that symbol across every in-scope account
     *   (neither)                 → every position on every in-scope account
     *
     * Runs inside the same dry-run transaction wrapper as the rest of
     * the command, so a `--dry-run --override` previews the wipe + the
     * rebuild together without persisting anything.
     */
    protected function wipeMatchingState($accounts, ?string $tokenFilter, RecoveryReport $report, bool $dryRun): void
    {
        $positionQuery = Position::query()
            ->whereIn('account_id', $accounts->pluck('id'));

        if ($tokenFilter !== null) {
            $positionQuery->where('parsed_trading_pair', mb_strtoupper($tokenFilter));
        }

        $positionIds = $positionQuery->pluck('id')->all();
        $positionCount = count($positionIds);

        if ($positionCount === 0) {
            $report->line(' Override:          0 local positions matched scope — nothing to wipe.');
            $report->line('');

            return;
        }

        $orderIds = Order::whereIn('position_id', $positionIds)->pluck('id')->all();
        $orderCount = count($orderIds);

        // Cancel in-flight (non-terminal) steps that reference these
        // position/order IDs in their JSON arguments. Without this,
        // any pending/dispatched/running step queued by the cron path
        // (e.g. PrepareSyncOrdersJob spawned by CheckDriftsCommand)
        // fires after the wipe and ModelNotFoundExceptions its way
        // into the Failed bucket — operator has to clean them by
        // hand. Marking them Cancelled (rather than deleting) keeps
        // the audit trail of "we WERE going to sync this, but the
        // operator wiped the position out from under us."
        $orphanCount = $this->cancelInflightStepsReferencing($positionIds, $orderIds);

        $report->line(sprintf(
            ' Override:          wiping %d position(s), %d order(s), cancelling %d in-flight step(s)%s',
            $positionCount,
            $orderCount,
            $orphanCount,
            $dryRun ? ' (dry-run — rolled back at end)' : '',
        ));
        $report->line('');

        Order::whereIn('position_id', $positionIds)->delete();
        Position::whereIn('id', $positionIds)->delete();
    }

    /**
     * Cancel non-terminal steps whose `arguments.positionId` or
     * `arguments.orderId` JSON path matches any of the about-to-be-
     * deleted entities. JSON_EXTRACT keeps the lookup index-friendly
     * for InnoDB even though the column is JSON.
     *
     * @param  array<int, int>  $positionIds
     * @param  array<int, int>  $orderIds
     */
    protected function cancelInflightStepsReferencing(array $positionIds, array $orderIds): int
    {
        if ($positionIds === [] && $orderIds === []) {
            return 0;
        }

        // Cancel matching rows in BOTH the default `steps` table AND the
        // `trading_steps` prefix. `Step::query()` is prefix-scoped via
        // `RuntimeContext::current()`, so a single call only sees the
        // ambient prefix's table — pre-fix, --override could delete a
        // position locally while leaving live `trading_steps` rows
        // referencing that id, which would then mutate the rebuilt
        // state on the next dispatcher tick.
        $cancelOne = function () use ($positionIds, $orderIds): int {
            $query = Step::query()->nonTerminal();

            $query->where(function ($q) use ($positionIds, $orderIds): void {
                if ($positionIds !== []) {
                    $q->orWhereIn(
                        DB::raw("CAST(JSON_EXTRACT(arguments, '$.positionId') AS UNSIGNED)"),
                        $positionIds
                    );
                }
                if ($orderIds !== []) {
                    $q->orWhereIn(
                        DB::raw("CAST(JSON_EXTRACT(arguments, '$.orderId') AS UNSIGNED)"),
                        $orderIds
                    );
                }
            });

            return $query->update([
                'state' => Cancelled::class,
                'error_message' => 'Cancelled by kraite:recover-positions --override (referenced position/order is being wiped)',
            ]);
        };

        $cancelledDefault = $cancelOne();
        $cancelledTrading = Steps::usingPrefix('trading', $cancelOne);

        return $cancelledDefault + $cancelledTrading;
    }

    /**
     * Deactivate the step dispatcher in every prefix this app uses
     * (default + trading). Called at recovery start; mirrored by
     * activateAllDispatchers() in the finally block.
     */
    protected function deactivateAllDispatchers(): void
    {
        StepDispatcher::deactivate();
        Steps::usingPrefix('trading', fn () => StepDispatcher::deactivate());
    }

    /**
     * Re-activate the step dispatcher in every prefix this app uses
     * (default + trading). Called from the finally block; safe to call
     * multiple times — `activate()` is idempotent.
     */
    protected function activateAllDispatchers(): void
    {
        StepDispatcher::activate();
        Steps::usingPrefix('trading', fn () => StepDispatcher::activate());
    }

    protected function renderReport(RecoveryReport $report): void
    {
        foreach ($report->lines as $line) {
            $this->line($line);
        }

        $this->line('');
        $this->line('======================================================================');
        $this->line(' Recovery Summary');
        $this->line('======================================================================');
        $this->line(sprintf(' Accounts checked:    %d', $report->accountsChecked));
        $this->line(sprintf(' Accounts OK:         %d', $report->accountsOk));
        $this->line(sprintf(' Accounts skipped:    %d', $report->accountsSkipped));
        $this->line(sprintf(' Positions created:   %d', $report->positionsCreated));
        $this->line(sprintf(' Positions updated:   %d', $report->positionsUpdated));
        $this->line(sprintf(' Positions skipped:   %d', $report->positionsSkipped));
        $this->line(sprintf(' Orders created:      %d', $report->ordersCreated));
        $this->line(sprintf(' Orders skipped:      %d (already present)', $report->ordersSkipped));
        $this->line('======================================================================');

        if ($report->warnings !== []) {
            $this->line('');
            $this->warn('Warnings:');
            foreach ($report->warnings as $w) {
                $this->warn('  • '.$w);
            }
        }
    }

    // =====================================================================
    // PHASE 5 — operational guards
    // =====================================================================

    /**
     * Flip `kraite.allow_opening_positions` to false for the duration
     * of the recovery. Returns the pre-run value so
     * {@see restoreTrading()} can put it back exactly. Flipping the
     * flag (rather than relying on the dispatcher freeze alone)
     * means even if the dispatcher restarts mid-run, the
     * PreparePositionsOpeningJob's gate refuses to ladder new
     * positions onto a half-recovered DB.
     */
    protected function freezeTrading(): bool
    {
        $engine = Kraite::find(1);

        if (! $engine) {
            return false;
        }

        $previousValue = (bool) $engine->allow_opening_positions;
        $engine->update(['allow_opening_positions' => false]);

        return $previousValue;
    }

    /**
     * Restore the pre-run value of `kraite.allow_opening_positions`,
     * but ONLY if the current value still matches the freeze recovery
     * applied (false). If another component flipped the flag during
     * recovery — e.g. a structure-broken halt from CheckDriftsCommand,
     * an operator manually pausing opens, a separate safety circuit —
     * the current value is no longer false, which proves recovery is
     * not the owner of this state any more. Restoring would silently
     * undo the newer safety halt.
     *
     * Idempotent: calling twice with the same value is a no-op.
     */
    protected function restoreTrading(bool $previousValue): void
    {
        $engine = Kraite::find(1);

        if (! $engine) {
            return;
        }

        // If the flag is no longer in the "frozen" state recovery set,
        // someone else changed it — don't clobber. The newer change
        // wins; an operator can re-warmup explicitly.
        if ((bool) $engine->allow_opening_positions !== false) {
            return;
        }

        if ((bool) $engine->allow_opening_positions === $previousValue) {
            return;
        }

        $engine->update(['allow_opening_positions' => $previousValue]);
    }

    /**
     * Pre-recovery DB snapshot. Dumps positions + orders to
     * `/tmp/kraite-recovery-{ts}.sql` so a botched recovery has a
     * known restore point. Mysql dump uses the kraite credentials
     * from `~/.credentials` (mirrors how every other on-host script
     * reads them). Failure here is logged + swallowed — a missing
     * snapshot must NOT abort the recovery (the recovery itself is
     * the more critical operation).
     *
     * Returns the absolute path to the snapshot file, or null on
     * failure.
     */
    protected function snapshotDatabase(RecoveryReport $report): ?string
    {
        $timestamp = now()->format('Ymd_His');
        $path = "/tmp/kraite-recovery-{$timestamp}.sql";

        $database = config('database.connections.mysql.database');
        $username = config('database.connections.mysql.username');
        $password = config('database.connections.mysql.password');
        $host = config('database.connections.mysql.host', '127.0.0.1');

        // Pass the password via MYSQL_PWD instead of `-p<pass>` on the
        // command line. The inline form leaks the credential through
        // /proc/<pid>/cmdline + `ps auxf` while the dump is running —
        // a real exposure on shared hosts. MYSQL_PWD survives only in
        // the child process's environment and is never serialised to
        // the process listing.
        $command = sprintf(
            'mysqldump --single-transaction --quick -h %s -u %s %s positions orders > %s 2>/dev/null',
            escapeshellarg((string) $host),
            escapeshellarg((string) $username),
            escapeshellarg((string) $database),
            escapeshellarg($path),
        );

        $exitCode = 0;
        $output = [];
        $previousPwd = getenv('MYSQL_PWD');
        putenv('MYSQL_PWD='.((string) $password));

        try {
            @exec($command, $output, $exitCode);
        } finally {
            // Restore previous MYSQL_PWD to avoid leaking the credential
            // into anything else this PHP process might exec later.
            if ($previousPwd === false) {
                putenv('MYSQL_PWD');
            } else {
                putenv('MYSQL_PWD='.$previousPwd);
            }
        }

        if ($exitCode !== 0 || ! is_file($path) || filesize($path) === 0) {
            $report->warning("Pre-recovery snapshot failed (exit={$exitCode}); proceeding without it");
            $report->line('  ⚠ Snapshot failed; proceeding without it');

            return null;
        }

        $sizeMib = number_format(filesize($path) / (1024 ** 2), 2);
        $report->line(" Snapshot:          {$path} ({$sizeMib} MiB)");

        return $path;
    }

    /**
     * Fire the `recovery_completed` canonical with the run summary
     * so the operator gets a Pushover / email / Telegram ping
     * confirming the recovery completed (vs silent stdout-only
     * confirmation today). Failure-contained — a notification
     * miss must NOT mark the recovery itself as failed.
     */
    protected function notifyCompletion(RecoveryReport $report, ?string $snapshotPath): void
    {
        try {
            $detail = sprintf(
                'Accounts: %d checked / %d ok / %d skipped. Positions: %d created / %d updated / %d skipped. Orders: %d created / %d skipped.',
                $report->accountsChecked,
                $report->accountsOk,
                $report->accountsSkipped,
                $report->positionsCreated,
                $report->positionsUpdated,
                $report->positionsSkipped,
                $report->ordersCreated,
                $report->ordersSkipped,
            );

            NotificationService::send(
                user: Kraite::admin(),
                canonical: 'recovery_completed',
                referenceData: [
                    'detail' => $detail,
                    'snapshot_path' => $snapshotPath,
                    'warnings_count' => count($report->warnings),
                ],
            );
        } catch (Throwable $exception) {
            Log::warning('[RecoverPositionsCommand] notifyCompletion failed; swallowed', [
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Single-box sequential recovery (deepest-disaster / dry-run path).
     * Full dispatcher freeze so no workflow picks up half-recovered state,
     * then each account runs all four phases through the shared runner
     * inside an optional dry-run transaction.
     */
    private function runInline($accounts, ?string $tokenFilter, bool $override, bool $dryRun, RecoveryReport $report): int
    {
        // Deactivate the step dispatcher in BOTH prefixes for the whole run.
        // `StepDispatcher::deactivate()` is prefix-scoped via
        // `RuntimeContext::current()`, so a single call would only freeze the
        // default `active.flag` and leave `trading_active.flag` running.
        $this->deactivateAllDispatchers();

        $allowUntested = (bool) $this->option('allow-untested-exchange');
        $rolledBack = false;

        try {
            if ($dryRun) {
                // Outer transaction wraps wipe + rebuild so --dry-run
                // --override previews the destructive path and rolls it back.
                DB::beginTransaction();
            }

            if ($override) {
                $this->wipeMatchingState($accounts, $tokenFilter, $report, $dryRun);
            }

            foreach ($accounts as $account) {
                $report->accountsChecked++;
                (new AccountRecoveryRunner($account, $report, $tokenFilter, $allowUntested))->run();
            }

            if ($dryRun) {
                DB::rollBack();
                $rolledBack = true;
                $report->line('');
                $report->line('[DRY-RUN] All writes rolled back. Re-run without --dry-run to persist.');
            }
        } catch (Throwable $e) {
            if ($dryRun && ! $rolledBack) {
                DB::rollBack();
            }

            $this->error('Recovery aborted: '.$e->getMessage());
            $report->warning('Aborted: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            // Always restore the dispatcher flag in BOTH prefixes.
            $this->activateAllDispatchers();
        }

        return self::SUCCESS;
    }

    /**
     * Fleet-distributed recovery. Fans one job per account across the worker
     * pool (StepRouter routes each away from IPs banned for that account's
     * exchange), then polls until every account settles and aggregates the
     * per-account reports. The dispatchers stay RUNNING — they route the
     * jobs; the freeze is `allow_opening_positions=false` alone.
     */
    private function runFanOut($accounts, ?string $tokenFilter, bool $override, RecoveryReport $report): int
    {
        // Wrap the whole fan-out so nothing escapes into handle() uncaught.
        // An uncaught throw here would skip restoreTrading() and leave the
        // fleet frozen (allow_opening_positions=false) with no report and no
        // notification — silently, until the operator noticed. Catching it
        // keeps trading frozen *deliberately* (the safe state) while still
        // rendering the report and returning FAILURE cleanly.
        try {
            $allowUntested = (bool) $this->option('allow-untested-exchange');

            // Global, atomic wipe once — never distributed into per-account jobs.
            if ($override) {
                $this->wipeMatchingState($accounts, $tokenFilter, $report, dryRun: false);
            }

            $workflowId = (string) Str::uuid();
            $dispatched = $this->fanOutRecoveryJobs($accounts, $tokenFilter, $allowUntested, $workflowId, $report);

            if ($dispatched === 0) {
                $report->warning('No recovery jobs were dispatched.');

                return self::FAILURE;
            }

            $this->info("Dispatched {$dispatched} per-account recovery job(s) across the fleet (workflow {$workflowId}). Waiting for completion...");

            if (! $this->pollUntilSettled($workflowId, $report)) {
                return self::FAILURE;
            }

            $failures = $this->aggregateFanOutResults($workflowId, $report);

            // "All steps reached a terminal state" is NOT "recovery
            // succeeded" — Failed / Stopped / Cancelled are terminal too.
            // If ANY account's job ended in a failed terminal state the DB
            // may be only partially rebuilt, so refuse to declare success:
            // returning FAILURE keeps trading frozen and suppresses the
            // false "recovery completed" notification.
            if ($failures > 0) {
                $report->warning("{$failures} account recovery job(s) failed to complete — trading stays frozen for operator review.");

                return self::FAILURE;
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            $report->warning('Fan-out recovery aborted with an exception — trading stays frozen: '.$e->getMessage());
            Log::channel('jobs')->error('[recover-positions] fan-out aborted', ['error' => $e->getMessage()]);

            return self::FAILURE;
        }
    }

    /**
     * Create one RecoverAccountPositionsJob step per account in the trading
     * prefix, tagged with a shared workflow id so the poll + aggregate can
     * find them. Per-account try/catch so one dispatch failure doesn't abort
     * the fan-out.
     */
    private function fanOutRecoveryJobs($accounts, ?string $tokenFilter, bool $allowUntested, string $workflowId, RecoveryReport $report): int
    {
        return Steps::usingPrefix('trading', function () use ($accounts, $tokenFilter, $allowUntested, $workflowId, $report): int {
            $dispatched = 0;

            foreach ($accounts as $account) {
                try {
                    Step::create([
                        'class' => RecoverAccountPositionsJob::class,
                        'queue' => 'positions',
                        'relatable_type' => Account::class,
                        'relatable_id' => $account->id,
                        'arguments' => [
                            'accountId' => $account->id,
                            'tokenFilter' => $tokenFilter,
                            'allowUntestedExchange' => $allowUntested,
                        ],
                        'workflow_id' => $workflowId,
                        'index' => 1,
                    ]);
                    $dispatched++;
                } catch (Throwable $e) {
                    $report->warning("Account #{$account->id}: recovery job dispatch failed — {$e->getMessage()}");
                }
            }

            return $dispatched;
        });
    }

    /**
     * Block until every per-account step for this workflow reaches a
     * terminal state, or the poll timeout elapses. Returns false on timeout
     * (jobs may still be running on the fleet).
     */
    private function pollUntilSettled(string $workflowId, RecoveryReport $report): bool
    {
        $timeoutMinutes = max(1, (int) $this->option('poll-timeout-minutes'));
        $deadline = now()->addMinutes($timeoutMinutes);

        while (now()->lessThan($deadline)) {
            [$total, $settled] = Steps::usingPrefix('trading', function () use ($workflowId): array {
                $total = Step::query()->where('workflow_id', $workflowId)->count();
                $settled = Step::query()->where('workflow_id', $workflowId)
                    ->whereIn('state', Step::terminalStepStates())
                    ->count();

                return [$total, $settled];
            });

            if ($total > 0 && $settled >= $total) {
                return true;
            }

            sleep(5);
        }

        $report->warning("Timed out after {$timeoutMinutes}m waiting for recovery jobs to settle — some accounts may still be recovering on the fleet.");

        return false;
    }

    /**
     * Roll each settled per-account step's result JSON up into the shared
     * report and return the number of steps that ended in a FAILED terminal
     * state. A failed step's `response` holds an exception dump, not recovery
     * metrics, so it must be detected by state — never read as a zero-count
     * success (that silently hid failed accounts and let the caller declare
     * the whole fan-out successful over an unrecovered DB).
     */
    private function aggregateFanOutResults(string $workflowId, RecoveryReport $report): int
    {
        $steps = Steps::usingPrefix('trading', fn () => Step::query()->where('workflow_id', $workflowId)->get());

        $failures = 0;

        foreach ($steps as $step) {
            $report->accountsChecked++;

            if ($this->fanOutStepFailed($step)) {
                $failures++;
                $report->accountsSkipped++;
                $report->warning("Account #{$step->relatable_id}: recovery job did not complete (state ".class_basename($step->state).').');

                continue;
            }

            $result = $this->decodeStepResult($step);

            if ($result === null) {
                $report->accountsSkipped++;
                $report->warning("Account #{$step->relatable_id}: recovery job returned no result payload.");

                continue;
            }

            $report->accountsOk += (int) ($result['accounts_ok'] ?? 0);
            $report->accountsSkipped += (int) ($result['accounts_skipped'] ?? 0);
            $report->positionsCreated += (int) ($result['positions_created'] ?? 0);
            $report->positionsUpdated += (int) ($result['positions_updated'] ?? 0);
            $report->positionsSkipped += (int) ($result['positions_skipped'] ?? 0);
            $report->ordersCreated += (int) ($result['orders_created'] ?? 0);
            $report->ordersSkipped += (int) ($result['orders_skipped'] ?? 0);

            foreach ((array) ($result['warnings'] ?? []) as $warning) {
                $report->warning((string) $warning);
            }
        }

        return $failures;
    }

    /**
     * A per-account fan-out step that ended Failed / Stopped / Cancelled did
     * NOT complete its account's reconciliation. Detected by state (not by
     * inspecting the payload) because a failed step still carries a
     * `response` — an exception dump — that would otherwise read as a valid
     * zero-count success.
     */
    private function fanOutStepFailed(Step $step): bool
    {
        return $step->state instanceof Failed
            || $step->state instanceof Stopped
            || $step->state instanceof Cancelled;
    }

    /**
     * Decode a step's stored return value (the `response` column) into an
     * array, tolerating both an array cast and a raw JSON string.
     *
     * @return array<string, mixed>|null
     */
    private function decodeStepResult(Step $step): ?array
    {
        $raw = $step->response ?? null;

        if (is_array($raw)) {
            return $raw;
        }

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, associative: true);

            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }
}
