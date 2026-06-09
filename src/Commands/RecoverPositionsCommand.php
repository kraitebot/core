<?php

declare(strict_types=1);

namespace Kraite\Core\Commands;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\Math;
use Kraite\Core\Support\NotificationService;
use Kraite\Core\Support\Recovery\RecovererResolver;
use Kraite\Core\Support\Recovery\RecoveryReport;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Cancelled;
use StepDispatcher\States\NotRunnable;
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
                            {--allow-snapshot-failure : Required for --override to proceed when the pre-run mysqldump snapshot fails. Default refusal protects the only restore point}';

    protected $description = 'Reconstruct local positions + orders from the exchange APIs (disaster recovery, idempotent)';

    public function handle(): int
    {
        $accountId = $this->option('account_id');
        $tokenFilter = $this->option('token');
        $dryRun = (bool) $this->option('dry-run');
        $override = (bool) $this->option('override');

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

        $this->printHeader($accounts->count(), $accountId, $tokenFilter, $dryRun, $override);

        // Deactivate the step dispatcher in BOTH prefixes for the whole
        // run so no workflow chains pick up half-recovered positions
        // mid-write. `StepDispatcher::deactivate()` is prefix-scoped via
        // `RuntimeContext::current()` — calling it once would only freeze
        // the default `active.flag`, leaving `trading_active.flag`
        // running. Trading workflows would keep dispatching against the
        // half-rebuilt DB. The flag is restored in the finally regardless
        // of outcome.
        $this->deactivateAllDispatchers();

        // Phase 5 — operational guards. Capture pre-run trading flag
        // so we restore it on completion. Dump positions + orders
        // BEFORE any writes happen so a botched recovery has a known
        // restore point. Both are guarded in the finally so a
        // mid-run exception doesn't leave the system frozen.
        $tradingWasOn = $this->freezeTrading();
        $snapshotPath = $dryRun ? null : $this->snapshotDatabase($report);

        // Snapshot is the only restore point a botched destructive
        // override can fall back on. Refuse to proceed with --override
        // when the snapshot failed unless the operator explicitly opts
        // in via --allow-snapshot-failure. Pre-fix, the snapshot
        // failure logged a warning and the destructive path continued.
        if ($override && ! $dryRun && $snapshotPath === null && ! (bool) $this->option('allow-snapshot-failure')) {
            $this->error('--override aborted: pre-run mysqldump snapshot failed and no restore point exists. Re-run with --allow-snapshot-failure if you have an alternative recovery plan.');
            $this->restoreTrading($tradingWasOn);
            $this->activateAllDispatchers();

            return self::FAILURE;
        }

        $rolledBack = false;

        // Per-account exchange-position keys captured during Phase 1
        // and consumed by Phases 2 + 4. Format: account_id => array<int, "SYMBOL:DIRECTION">.
        $exchangeKeysByAccount = [];

        try {
            if ($dryRun) {
                // Wrap the entire run — wipe + rebuild — in an outer
                // transaction that we explicitly roll back at the end.
                // The per-position transactions inside become savepoints.
                // The wipe MUST be inside this transaction; if it ran
                // before the transaction began, --dry-run --override
                // would actually delete positions and only the rebuild
                // would roll back, leaving the DB in a permanently
                // broken state.
                DB::beginTransaction();
            }

            if ($override) {
                $this->wipeMatchingState($accounts, $tokenFilter, $report, $dryRun);
            }

            // Phase 1 — exchange → local. Walk every open position on
            // every in-scope account; create / reconcile.
            foreach ($accounts as $account) {
                $report->accountsChecked++;
                $this->processAccount($account, $tokenFilter, $report);
                $exchangeKeysByAccount[$account->id] = $this->fetchExchangePositionKeys($account);
            }

            // Phase 2 — local → exchange close-detection. Local
            // open-status positions whose key isn't on the exchange
            // closed during the gap. Mark closed.
            $this->markClosedDuringGap($accounts, $tokenFilter, $exchangeKeysByAccount, $report);

            // Phase 3 — order-status mirror. Local non-terminal
            // orders on still-active positions get apiSync'd so any
            // CANCELLED / FILLED / EXPIRED status drift from the gap
            // is reflected locally.
            $this->mirrorOrderStatuses($accounts, $tokenFilter, $report);

            // Phase 4 — stuck-state reset. Positions in
            // opening / syncing / cancelling that no longer have an
            // in-flight workflow get reset based on exchange truth.
            $this->resetStuckStates($accounts, $tokenFilter, $exchangeKeysByAccount, $report);

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
            $this->renderReport($report);
            $this->restoreTrading($tradingWasOn);

            return self::FAILURE;
        } finally {
            // Always restore the dispatcher flag in BOTH prefixes — even
            // on exception — so workers don't stay idle indefinitely
            // after a failed run. Mirrors the dual-prefix freeze above.
            $this->activateAllDispatchers();
        }

        $this->renderReport($report);

        // Phase 5 — restore trading flag + fire completion notification.
        // Trading restore lives outside the try/catch so a successful
        // recovery flips it back exactly once; failures restored it
        // in the catch block above.
        $this->restoreTrading($tradingWasOn);

        if (! $dryRun) {
            $this->notifyCompletion($report, $snapshotPath);
        }

        return self::SUCCESS;
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

    /**
     * Run recovery for one account. Health-check first; on failure the
     * account is logged and skipped without touching the DB.
     */
    protected function processAccount(Account $account, ?string $tokenFilter, RecoveryReport $report): void
    {
        $exchange = $account->apiSystem->canonical ?? 'unknown';

        $report->line("=== Account #{$account->id} ({$account->name}, {$exchange}) ===");

        if (! $this->healthCheck($account, $report)) {
            $report->accountsSkipped++;

            return;
        }

        try {
            $recoverer = RecovererResolver::for($account, $report, $tokenFilter);

            if ($recoverer->isUntested() && ! (bool) $this->option('allow-untested-exchange')) {
                $report->accountsSkipped++;
                $report->warning(
                    "Account #{$account->id} ({$exchange}) skipped — recoverer flagged untested. Re-run with --allow-untested-exchange to permit."
                );
                $report->line('  ⚠ Recoverer is flagged UNTESTED for this exchange. Skipped (use --allow-untested-exchange to override).');

                return;
            }

            $recoverer->run();
            $report->accountsOk++;
        } catch (Throwable $e) {
            $report->accountsSkipped++;
            $report->warning("Account #{$account->id} ({$exchange}) aborted: {$e->getMessage()}");
            $report->line("  ✗ Recoverer aborted: {$e->getMessage()}");
        }
    }

    /**
     * Light authenticated round-trip via apiQueryBalance. Verifies
     * API credentials are valid before recovery runs. Failure here
     * means the rest of the recovery is guaranteed to fail too.
     */
    protected function healthCheck(Account $account, RecoveryReport $report): bool
    {
        try {
            $response = $account->apiQueryBalance();

            if ($response->result === []) {
                $report->warning("Account #{$account->id}: empty balance response — credentials likely invalid");
                $report->line('  ✗ API health-check returned empty body');

                return false;
            }

            $report->line('  ✓ API credentials valid');

            return true;
        } catch (Throwable $e) {
            $report->warning("Account #{$account->id}: balance call failed — {$e->getMessage()}");
            $report->line("  ✗ API health-check failed: {$e->getMessage()}");

            return false;
        }
    }

    protected function printHeader(int $accountCount, $accountId, ?string $tokenFilter, bool $dryRun, bool $override): void
    {
        $this->line('');
        $this->line('======================================================================');
        $this->line(' Kraite Position Recovery');
        $this->line('======================================================================');
        $this->line(' Accounts in scope: '.$accountCount.($accountId !== null ? " (account_id={$accountId})" : ''));

        if ($tokenFilter !== null) {
            $this->line(' Token filter:      '.$tokenFilter);
        }

        $this->line(' Dry-run:           '.($dryRun ? 'YES (no writes will persist)' : 'NO'));
        $this->line(' Override:          '.($override ? 'YES (matching positions+orders will be wiped first)' : 'NO'));
        $this->line(' Dispatcher:        deactivated for the duration of this run');
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
            $terminal = Step::terminalStepStates();

            $query = Step::query()->whereNotIn('state', $terminal);

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

    // =====================================================================
    // PHASE 2 — close-detection sweep
    // =====================================================================

    /**
     * Pull a list of "SYMBOL:DIRECTION" keys from the exchange's
     * current open-positions snapshot for the given account. Used
     * by Phases 2 + 4 to compare against local DB state.
     *
     * Mirrors the normalisation logic in
     * `CheckSystemHealthCommand::reconcileAccountOrphans` —
     * one-way mode returns positions keyed `SYMBOL:BOTH`, derive
     * LONG / SHORT from the `positionAmt` sign so the comparison
     * is apples-to-apples regardless of mode.
     *
     * Failure (account API down, network blip) returns an EMPTY
     * array. The Phase 2 sweep treats an empty exchange snapshot
     * the same as "every local position closed during the gap"
     * — which is exactly wrong if the API is just temporarily
     * unreachable. To prevent false closes, callers should refuse
     * to mutate state if this returns empty AND there are local
     * open positions for the account; that guard lives in
     * `markClosedDuringGap()`.
     *
     * @return array<int, string>
     */
    protected function fetchExchangePositionKeys(Account $account): array
    {
        try {
            $response = $account->apiQueryPositions();
            $positions = collect($response->result ?? []);
        } catch (Throwable) {
            return [];
        }

        $keys = [];
        foreach ($positions as $key => $row) {
            // Multi-exchange quantity-field precedence — Binance uses
            // `positionAmt`, Bybit uses `size`, Bitget uses `total`,
            // KuCoin uses `currentQty` / `contracts`. Mirrors the same
            // shape that AbstractPositionRecoverer::absQuantity() handles.
            // Pre-fix, only `positionAmt` was read, which yielded an
            // empty key list for non-Binance recoverers and would let
            // Phase 4 close real positions as ghosts.
            $amt = (string) ($row['positionAmt']
                ?? $row['size']
                ?? $row['total']
                ?? $row['currentQty']
                ?? $row['contracts']
                ?? '0');

            if (Math::equal($amt, '0')) {
                continue;
            }

            [$symbol, $side] = array_pad(explode(':', (string) $key), 2, 'BOTH');

            if ($side === 'BOTH') {
                $side = Math::gt($amt, '0') ? 'LONG' : 'SHORT';
            }

            $keys[] = "{$symbol}:{$side}";
        }

        return $keys;
    }

    /**
     * Walk every LOCAL position in opened-status for in-scope
     * accounts. For each whose `(symbol, direction)` key is NOT in
     * the exchange snapshot for that account, flip status to
     * `closed` with `closed_at = now()`. The position closed
     * during the recovery gap (T-snapshot to T-recovery) and
     * lost-history is acceptable per the disaster-recovery
     * scope.
     *
     * Safety guard: if the exchange snapshot for an account is
     * EMPTY but the local DB has open positions for that
     * account, refuse to mutate. An empty snapshot can mean
     * "API failure" rather than "no positions" and we don't want
     * to mass-close real positions on a transient failure. The
     * `fetchExchangePositionKeys()` health-check already runs
     * inside the recoverer; this is belt + braces.
     *
     * @param  iterable<Account>  $accounts
     * @param  array<int, array<int, string>>  $exchangeKeysByAccount
     */
    protected function markClosedDuringGap(
        $accounts,
        ?string $tokenFilter,
        array $exchangeKeysByAccount,
        RecoveryReport $report,
    ): void {
        $report->line('');
        $report->line('=== Phase 2 — close-detection sweep ===');

        $closedCount = 0;

        foreach ($accounts as $account) {
            $exchangeKeys = $exchangeKeysByAccount[$account->id] ?? [];

            $localOpenQuery = Position::query()
                ->where('account_id', $account->id)
                ->whereIn('status', (new Position)->openedStatuses());

            if ($tokenFilter !== null) {
                $localOpenQuery->where('parsed_trading_pair', mb_strtoupper($tokenFilter));
            }

            $localOpen = $localOpenQuery->get();

            if ($localOpen->isEmpty()) {
                continue;
            }

            // Safety guard: empty exchange snapshot + non-empty
            // local set may indicate an API failure. Skip rather
            // than mass-close on a transient blip. The recoverer's
            // health-check already covers this but the guard is
            // cheap insurance.
            if ($exchangeKeys === [] && $localOpen->isNotEmpty()) {
                $report->line("  → Account #{$account->id}: exchange snapshot empty + {$localOpen->count()} local open position(s) — skipping close-detection (treat as transient API failure)");

                continue;
            }

            foreach ($localOpen as $position) {
                $key = "{$position->parsed_trading_pair}:{$position->direction}";

                if (in_array($key, $exchangeKeys, true)) {
                    continue;
                }

                $position->updateSaving([
                    'status' => 'closed',
                    'closed_at' => now(),
                    'error_message' => 'Closed during disaster-recovery gap; exchange snapshot at T-recovery did not contain this position',
                ]);

                $closedCount++;
                $report->line("  ✓ Position #{$position->id} ({$position->parsed_trading_pair} {$position->direction}) marked closed (not on exchange)");
            }
        }

        if ($closedCount === 0) {
            $report->line('  → No phantom positions to close.');
        } else {
            $report->line("  → Closed {$closedCount} phantom position(s).");
        }
    }

    // =====================================================================
    // PHASE 3 — order-status mirror
    // =====================================================================

    /**
     * Walk every LOCAL non-terminal order on STILL-active positions
     * for in-scope accounts. For each, call `apiSync()` so any
     * CANCELLED / FILLED / EXPIRED state drift from the recovery
     * gap is reflected locally. Per-order failures (e.g. exchange
     * "Unknown order sent" -2011) are caught + logged so a single
     * stale row doesn't abort the whole pass.
     *
     * Cost: one REST call per order. For typical books (60–200
     * orders) the latency is in the seconds range. Acceptable for
     * a once-a-disaster command.
     */
    protected function mirrorOrderStatuses($accounts, ?string $tokenFilter, RecoveryReport $report): void
    {
        $report->line('');
        $report->line('=== Phase 3 — order-status mirror ===');

        $synced = 0;
        $failed = 0;

        foreach ($accounts as $account) {
            $orderQuery = Order::query()
                ->whereIn('status', ['NEW', 'PARTIALLY_FILLED'])
                ->whereNotNull('exchange_order_id')
                ->whereHas('position', function ($q) use ($account, $tokenFilter): void {
                    $q->where('account_id', $account->id)
                        ->whereIn('status', (new Position)->openedStatuses());

                    if ($tokenFilter !== null) {
                        $q->where('parsed_trading_pair', mb_strtoupper($tokenFilter));
                    }
                });

            $orders = $orderQuery->get();

            if ($orders->isEmpty()) {
                continue;
            }

            foreach ($orders as $order) {
                try {
                    // Suppress OrderObserver during the mirror pass so a
                    // status change (NEW → FILLED, NEW → CANCELLED) does
                    // NOT dispatch Close / Wap / Replacement jobs against
                    // the half-recovered DB. The remaining recovery
                    // phases (4 stuck-state reset, post-recovery
                    // reconciliation) handle the intentional state
                    // transitions; the dispatcher stays deactivated
                    // until the finally block re-activates it.
                    Order::withoutEvents(fn () => $order->apiSync());
                    $synced++;
                } catch (Throwable $exception) {
                    $failed++;
                    Log::warning('[RecoverPositionsCommand] mirrorOrderStatuses apiSync failed', [
                        'order_id' => $order->id,
                        'exchange_order_id' => $order->exchange_order_id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
        }

        $report->line("  → Synced {$synced} order(s); {$failed} failure(s) (logged).");
    }

    // =====================================================================
    // PHASE 4 — stuck-state reset
    // =====================================================================

    /**
     * Walk every LOCAL position in `opening` / `syncing` /
     * `cancelling` for in-scope accounts. For each:
     *   - If exchange shows the position open → flip to `active`.
     *   - If exchange doesn't show it → flip to `closed`.
     *
     * The pre-condition is "no in-flight workflow can be running",
     * which is enforced by the dispatcher freeze at the top of the
     * run + the no-pending-step check below. Without this phase,
     * positions that were mid-workflow when the disaster hit stay
     * pinned in non-terminal status forever — the bot won't
     * re-engage them and the operator must intervene by hand.
     *
     * @param  iterable<Account>  $accounts
     * @param  array<int, array<int, string>>  $exchangeKeysByAccount
     */
    protected function resetStuckStates(
        $accounts,
        ?string $tokenFilter,
        array $exchangeKeysByAccount,
        RecoveryReport $report,
    ): void {
        $report->line('');
        $report->line('=== Phase 4 — stuck-state reset ===');

        $reset = 0;
        $closedAsGhost = 0;

        $stuckStatuses = ['opening', 'syncing', 'cancelling'];

        foreach ($accounts as $account) {
            $exchangeKeys = $exchangeKeysByAccount[$account->id] ?? [];

            $stuckQuery = Position::query()
                ->where('account_id', $account->id)
                ->whereIn('status', $stuckStatuses);

            if ($tokenFilter !== null) {
                $stuckQuery->where('parsed_trading_pair', mb_strtoupper($tokenFilter));
            }

            $stuck = $stuckQuery->get();

            // Belt-and-braces against false ghost-closing. An empty
            // exchange-keys list for an account that DOES have stuck
            // positions can mean (a) all those positions really did
            // close during the gap, or (b) the API call to fetch
            // positions failed and the stale-snapshot guard collapsed
            // them all to "ghost". Phase 2 has the same shape of guard
            // (markClosedDuringGap); Phase 4 inherits it here so both
            // close-detection paths refuse to mass-close on empty
            // snapshots that may just reflect transient API failure
            // or non-Binance key extraction missing the quantity field.
            if ($exchangeKeys === [] && $stuck->isNotEmpty()) {
                $report->warning(
                    "Account #{$account->id}: empty exchange snapshot but {$stuck->count()} stuck position(s) — refusing to ghost-close (likely API failure or non-Binance key extraction gap)"
                );
                $report->line("  ⚠ Account #{$account->id}: skipped Phase 4 — empty exchange snapshot, {$stuck->count()} stuck position(s) preserved");

                continue;
            }

            foreach ($stuck as $position) {
                // The Phase 4 invariant — "no in-flight workflow can be
                // running" — requires an actual check, not just the
                // dispatcher freeze above. A queued/pending Step row
                // still REFERENCES the position via JSON arguments;
                // resetting the position's status would mean that
                // when the dispatcher reactivates in the finally block
                // it picks up the queued step and mutates state recovery
                // just rewrote. Skip + warn so the operator can manually
                // cancel + re-run.
                if ($this->hasInflightStepFor($position->id)) {
                    $report->warning(
                        "Position #{$position->id} ({$position->parsed_trading_pair} {$position->direction}) skipped — in-flight workflow step(s) still reference it"
                    );
                    $report->line("  ⚠ Position #{$position->id} ({$position->parsed_trading_pair} {$position->direction}): kept in {$position->status} (in-flight workflow still owns it)");

                    continue;
                }

                $key = "{$position->parsed_trading_pair}:{$position->direction}";

                if (in_array($key, $exchangeKeys, true)) {
                    $position->updateSaving(['status' => 'active']);
                    $reset++;
                    $report->line("  ✓ Position #{$position->id} ({$position->parsed_trading_pair} {$position->direction}): {$position->getOriginal('status')} → active (exchange has it)");

                    continue;
                }

                $position->updateSaving([
                    'status' => 'closed',
                    'closed_at' => now(),
                    'error_message' => "Closed during disaster-recovery (stuck in {$position->getOriginal('status')} with no exchange-side position)",
                ]);
                $closedAsGhost++;
                $report->line("  ✓ Position #{$position->id} ({$position->parsed_trading_pair} {$position->direction}): {$position->getOriginal('status')} → closed (exchange has nothing)");
            }
        }

        $report->line("  → Reset {$reset} stuck position(s) to active; closed {$closedAsGhost} ghost(s).");
    }

    /**
     * Returns true when any non-terminal Step row in EITHER the default
     * `steps` table OR the `trading_steps` prefix references this
     * positionId via JSON arguments. Used by Phase 4 to honour the
     * "no in-flight workflow" invariant before rewriting status.
     */
    protected function hasInflightStepFor(int $positionId): bool
    {
        $check = function () use ($positionId): bool {
            // Exclude NotRunnable alongside the terminal states. NotRunnable is
            // the parked state for resolve-exception ("rescue") steps that only
            // run if a sibling in their block fails; on the happy path they sit
            // there forever and the dispatcher itself excludes them from
            // dispatch. Counting an inert rescue branch as an in-flight workflow
            // makes recovery defer forever on any successfully-opened position
            // (which always leaves a NotRunnable CancelPositionJob behind). A
            // genuinely failing block still trips this guard via its real
            // non-terminal sibling (Pending/Dispatched/Running).
            return Step::query()
                ->whereNotIn('state', array_merge(Step::terminalStepStates(), [NotRunnable::class]))
                ->whereRaw(
                    "CAST(JSON_EXTRACT(arguments, '$.positionId') AS UNSIGNED) = ?",
                    [$positionId],
                )
                ->exists();
        };

        if ($check()) {
            return true;
        }

        return (bool) Steps::usingPrefix('trading', $check);
    }
}
