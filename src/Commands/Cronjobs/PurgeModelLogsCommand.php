<?php

declare(strict_types=1);

namespace Kraite\Core\Commands\Cronjobs;

use Illuminate\Support\Facades\DB;
use StepDispatcher\Support\BaseCommand;

/**
 * PurgeModelLogsCommand
 *
 * Purges rows from the `model_logs` table older than the given retention
 * window. The table logs every attribute change on allowlisted models
 * (Account, ExchangeSymbol, Order, Position, Symbol) and every attribute-
 * created event, each stamped with `relatable_type = StepDispatcher\Step`
 * + `relatable_id` so the driving step is queryable.
 *
 * That log IS the causality trail we use to replay a position's
 * lifecycle (fills, WAP transitions, close chain, reference updates).
 * Unbounded retention would blow the table up — at scale, every tick
 * of sync-orders across a few dozen positions writes thousands of rows.
 * So we prune on a daily schedule, keeping a rolling window long enough
 * for operational forensics but short enough to stay manageable.
 *
 * Safety:
 * - Chunked deletes (default 1000 rows per batch) to avoid long table
 *   locks and replication lag spikes.
 * - `--dry-run` to preview the count before committing.
 */
final class PurgeModelLogsCommand extends BaseCommand
{
    protected $signature = 'kraite:purge-model-logs
                            {--duration=30 : Retention window in days (default: 30)}
                            {--chunk=1000 : Rows to delete per batch (default: 1000)}
                            {--dry-run : Show what would be deleted without deleting}
                            {--output : Display command output (silent by default)}';

    protected $description = 'Purge model_logs rows older than the retention window.';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('duration'));
        $chunkSize = max(100, (int) $this->option('chunk'));
        $dryRun = (bool) $this->option('dry-run');

        $threshold = now()->subDays($days);

        $this->verboseInfo(sprintf(
            'Purging model_logs rows older than %s (%d day(s) retention)...',
            $threshold->toDateTimeString(),
            $days
        ));

        if ($dryRun) {
            $count = DB::table('model_logs')
                ->where('created_at', '<', $threshold)
                ->count();

            $this->verboseWarn('DRY RUN - no data will be deleted');
            $this->verboseInfo("Would delete {$count} row(s).");

            return self::SUCCESS;
        }

        $totalDeleted = 0;

        do {
            $deleted = DB::table('model_logs')
                ->where('created_at', '<', $threshold)
                ->limit($chunkSize)
                ->delete();

            $totalDeleted += $deleted;
        } while ($deleted > 0);

        $this->verboseInfo("Deleted {$totalDeleted} model_logs row(s).");

        return self::SUCCESS;
    }
}
