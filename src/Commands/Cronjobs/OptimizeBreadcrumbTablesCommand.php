<?php

declare(strict_types=1);

namespace Kraite\Core\Commands\Cronjobs;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Kraite\Core\Support\MaintenanceMode;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Dispatched;
use StepDispatcher\States\Running;
use StepDispatcher\Support\BaseCommand;

/**
 * OptimizeBreadcrumbTablesCommand
 *
 * Runs `OPTIMIZE TABLE` on the high-churn breadcrumb tables. The
 * PurgePositionTrailJob janitor + the daily PurgeOldDataCommand + the
 * steps:purge cycle delete large row counts every day; InnoDB's
 * deletions free pages inside the .ibd file but never return that
 * space to the OS, so without a periodic rebuild the table files keep
 * growing even when row counts stay flat.
 *
 * Tables touched (full set, when no --table provided):
 *
 *   - model_logs       (attribute-change audit trail)
 *   - api_request_logs (every outbound exchange / TAAPI call)
 *   - api_snapshots    (per-call response cache)
 *   - steps            (live job pipeline)
 *   - steps_archive    (resolved step trees)
 *
 * Per-table managed window:
 *
 *   Each table is rebuilt inside its own maintenance window:
 *
 *     1. `MaintenanceMode::pauseStepsDispatch()` so the cron
 *        scheduler stops kicking off `steps:dispatch` ticks. New
 *        Pending → Dispatched promotions are gated for the duration.
 *     2. Wait for in-flight Running / Dispatched steps to drain
 *        (capped by DRAIN_TIMEOUT_SECONDS).
 *     3. Run a single `OPTIMIZE TABLE` on this table. InnoDB's
 *        rebuild is single-threaded per table; that's fine — we want
 *        each rebuild to own the disk bandwidth instead of competing
 *        with siblings (parallel rebuilds tripled per-table duration
 *        in production benchmarks).
 *     4. `MaintenanceMode::resumeStepsDispatch()` in a finally so
 *        dispatch always reopens, even on exception. Cache TTL on the
 *        pause is the secondary safety net.
 *
 *   When invoked with no `--table` arguments the command iterates the
 *   full breadcrumb set sequentially, applying the maintenance wrap
 *   to each table independently — short pauses with quick resumes
 *   between them so the dispatcher catches up rather than sitting
 *   gated for the whole run.
 *
 *   The scheduler stitches the same per-table behaviour across the
 *   night by registering one schedule entry per table at staggered
 *   times (see routes/console.php).
 */
final class OptimizeBreadcrumbTablesCommand extends BaseCommand
{
    /**
     * Default rebuild set. Order is deterministic so the schedule log
     * lines line up day-over-day for trend comparison.
     *
     * @var list<string>
     */
    public const TABLES = [
        'model_logs',
        'api_request_logs',
        'api_snapshots',
        'steps',
        'steps_archive',
    ];

    /**
     * Max time (seconds) to wait for the in-flight Running /
     * Dispatched steps to drain after engaging the dispatch pause.
     * Beyond this cap we proceed with OPTIMIZE anyway — the rebuild
     * still works, the stragglers will just block momentarily on the
     * end-of-rebuild metadata lock.
     */
    private const DRAIN_TIMEOUT_SECONDS = 60;

    /**
     * Poll interval (microseconds) while waiting for the drain. 250ms
     * is fine-grained enough to catch a near-empty pipeline within a
     * few ticks, coarse enough to avoid hammering the steps table
     * with COUNT-ish queries.
     */
    private const DRAIN_POLL_MICROSECONDS = 250_000;

    protected $signature = 'kraite:cron-optimize-breadcrumb-tables
                            {--table=* : Limit the run to specific table names (repeatable). When omitted, the full breadcrumb set is rebuilt sequentially with a per-table maintenance window.}
                            {--output : Display command output (silent by default)}';

    protected $description = 'Run OPTIMIZE TABLE on the breadcrumb tables. Each rebuild runs inside its own paused-dispatch maintenance window; tables are processed sequentially.';

    public function handle(): int
    {
        $requested = (array) $this->option('table');
        $tables = $requested === [] ? self::TABLES : array_values(array_intersect(self::TABLES, $requested));

        if ($tables === []) {
            $this->verboseWarn('No matching breadcrumb tables to optimise.');

            return self::SUCCESS;
        }

        foreach ($tables as $table) {
            $this->optimiseWithMaintenanceWindow($table);
        }

        return self::SUCCESS;
    }

    /**
     * Run a managed OPTIMIZE on a single table:
     * pause → drain → optimize → resume (resume in finally).
     */
    private function optimiseWithMaintenanceWindow(string $table): void
    {
        $totalStart = microtime(true);

        MaintenanceMode::pauseStepsDispatch(
            reason: "OPTIMIZE TABLE {$table}",
        );

        $this->verboseInfo(sprintf(
            '[OPTIMIZE-BREADCRUMBS] %s — steps:dispatch paused, waiting for in-flight steps to drain.',
            $table,
        ));

        try {
            $drain = $this->waitForStepsToDrain();
            $this->optimiseTable($table, $drain, $totalStart);
        } finally {
            MaintenanceMode::resumeStepsDispatch();
            $this->verboseInfo(sprintf(
                '[OPTIMIZE-BREADCRUMBS] %s — steps:dispatch resumed.',
                $table,
            ));
        }
    }

    /**
     * Block the calling process until the steps table has no rows in
     * Running or Dispatched, capped by DRAIN_TIMEOUT_SECONDS.
     *
     * @return array{drained: bool, waited_seconds: float, residual_active: int}
     */
    private function waitForStepsToDrain(): array
    {
        $deadline = microtime(true) + self::DRAIN_TIMEOUT_SECONDS;
        $start = microtime(true);

        while (microtime(true) < $deadline) {
            $active = Step::query()
                ->whereIn('state', [Running::class, Dispatched::class])
                ->count();

            if ($active === 0) {
                return [
                    'drained' => true,
                    'waited_seconds' => round(microtime(true) - $start, 2),
                    'residual_active' => 0,
                ];
            }

            usleep(self::DRAIN_POLL_MICROSECONDS);
        }

        $residual = Step::query()
            ->whereIn('state', [Running::class, Dispatched::class])
            ->count();

        $this->verboseWarn(sprintf(
            '[OPTIMIZE-BREADCRUMBS] drain timeout — proceeding with %d step(s) still active.',
            $residual,
        ));

        return [
            'drained' => false,
            'waited_seconds' => round(microtime(true) - $start, 2),
            'residual_active' => $residual,
        ];
    }

    /**
     * Run one OPTIMIZE TABLE and emit a structured scheduler-log line
     * with the before/after MB + duration + drain context.
     *
     * @param  array{drained: bool, waited_seconds: float, residual_active: int}  $drain
     */
    private function optimiseTable(string $table, array $drain, float $windowStart): void
    {
        $beforeMb = $this->tableSizeMb($table);

        $start = microtime(true);
        DB::statement("OPTIMIZE TABLE {$table}");
        $rebuildSeconds = round(microtime(true) - $start, 2);

        $afterMb = $this->tableSizeMb($table);
        $deltaMb = $beforeMb - $afterMb;
        $totalWindowSeconds = round(microtime(true) - $windowStart, 2);

        $line = sprintf(
            '[OPTIMIZE-BREADCRUMBS] %s before=%dMB after=%dMB delta=%dMB rebuild=%.2fs window=%.2fs',
            $table,
            $beforeMb,
            $afterMb,
            $deltaMb,
            $rebuildSeconds,
            $totalWindowSeconds,
        );

        $this->verboseInfo($line);

        Log::channel('scheduler')->info($line, [
            'table' => $table,
            'before_mb' => $beforeMb,
            'after_mb' => $afterMb,
            'delta_mb' => $deltaMb,
            'rebuild_seconds' => $rebuildSeconds,
            'window_seconds' => $totalWindowSeconds,
            'drain' => $drain,
        ]);
    }

    private function tableSizeMb(string $table): int
    {
        $row = DB::selectOne(
            'SELECT ROUND((data_length+index_length)/1024/1024) AS mb
             FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?',
            [$table],
        );

        return (int) ($row->mb ?? 0);
    }
}
