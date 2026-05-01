<?php

declare(strict_types=1);

namespace Kraite\Core\Commands;

use Illuminate\Support\Facades\DB;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\Recovery\RecovererResolver;
use Kraite\Core\Support\Recovery\RecoveryReport;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Cancelled;
use StepDispatcher\Support\BaseCommand;
use StepDispatcher\Support\StepDispatcher;
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
                            {--override : Delete matching local positions+orders BEFORE recovery so the rebuild is from scratch (scoped by --account_id and --token if set)}';

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

        $report = new RecoveryReport;
        $report->dryRun = $dryRun;

        $this->printHeader($accounts->count(), $accountId, $tokenFilter, $dryRun, $override);

        // Deactivate the step dispatcher for the whole run so no
        // workflow chains pick up half-recovered positions mid-write.
        // The flag is restored in the finally regardless of outcome.
        StepDispatcher::deactivate();

        $rolledBack = false;

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

            foreach ($accounts as $account) {
                $report->accountsChecked++;
                $this->processAccount($account, $tokenFilter, $report);
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
            $this->renderReport($report);

            return self::FAILURE;
        } finally {
            // Always restore the dispatcher flag — even on exception —
            // so workers don't stay idle indefinitely after a failed run.
            StepDispatcher::activate();
        }

        $this->renderReport($report);

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
            $recoverer->run();
            $report->accountsOk++;
        } catch (Throwable $e) {
            $report->accountsSkipped++;
            $report->warning("Account #{$account->id} ({$exchange}) aborted: {$e->getMessage()}");
            $report->line("  ✗ Recoverer aborted: {$e->getMessage()}");
        }
    }

    /**
     * Light authenticated round-trip via getAccountBalance. Verifies
     * API credentials are valid before recovery runs. Failure here
     * means the rest of the recovery is guaranteed to fail too.
     */
    protected function healthCheck(Account $account, RecoveryReport $report): bool
    {
        try {
            $response = $account->withApi()->getAccountBalance();
            $body = (string) $response->getBody();

            if (mb_strlen($body) < 10) {
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
}
