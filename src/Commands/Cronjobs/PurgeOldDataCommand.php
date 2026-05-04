<?php

declare(strict_types=1);

namespace Kraite\Core\Commands\Cronjobs;

use Illuminate\Support\Facades\DB;
use StepDispatcher\Support\BaseCommand;

/**
 * PurgeOldDataCommand
 *
 * Purges rows from high-volume operational log tables on a daily schedule.
 * Two tables are handled in a single run:
 *
 * 1. `api_request_logs` — every outbound HTTP call to Binance, Bitget,
 *    Bybit, KuCoin, TAAPI, etc. is mirrored here (insert at request time,
 *    update on completion). Volume is dominated by the kline-fetch and
 *    sync-orders cron fan-outs and easily reaches hundreds of thousands
 *    of rows per day. Useful as a forensic trail for failed requests but
 *    successful 200s become noise quickly. Default retention: 5 days.
 *
 * 2. `model_logs` — attribute-change audit trail for the trading domain
 *    (Account, ExchangeSymbol, Order, Position, Symbol). Required for
 *    replaying a position's lifecycle. Default retention: 30 days.
 *
 * Safety:
 * - Chunked deletes to avoid long table locks and replication lag spikes.
 * - `--dry-run` previews the row counts before deleting.
 */
final class PurgeOldDataCommand extends BaseCommand
{
    protected $signature = 'kraite:purge-old-data
                            {--api-request-logs-days=5 : Retention window for api_request_logs in days}
                            {--model-logs-days=30 : Retention window for model_logs in days}
                            {--chunk=1000 : Rows to delete per batch}
                            {--dry-run : Show what would be deleted without deleting}
                            {--output : Display command output (silent by default)}';

    protected $description = 'Purge api_request_logs and model_logs rows older than their retention windows.';

    public function handle(): int
    {
        $chunkSize = max(100, (int) $this->option('chunk'));
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->verboseWarn('DRY RUN - no data will be deleted');
        }

        $this->purgeTable(
            table: 'api_request_logs',
            days: max(1, (int) $this->option('api-request-logs-days')),
            chunkSize: $chunkSize,
            dryRun: $dryRun,
        );

        $this->purgeTable(
            table: 'model_logs',
            days: max(1, (int) $this->option('model-logs-days')),
            chunkSize: $chunkSize,
            dryRun: $dryRun,
        );

        return self::SUCCESS;
    }

    private function purgeTable(string $table, int $days, int $chunkSize, bool $dryRun): void
    {
        $threshold = now()->subDays($days);

        $this->verboseInfo(sprintf(
            'Purging %s rows older than %s (%d day(s) retention)...',
            $table,
            $threshold->toDateTimeString(),
            $days,
        ));

        if ($dryRun) {
            $count = DB::table($table)
                ->where('created_at', '<', $threshold)
                ->count();

            $this->verboseInfo("Would delete {$count} {$table} row(s).");

            return;
        }

        $totalDeleted = 0;

        do {
            $deleted = DB::table($table)
                ->where('created_at', '<', $threshold)
                ->limit($chunkSize)
                ->delete();

            $totalDeleted += $deleted;
        } while ($deleted > 0);

        $this->verboseInfo("Deleted {$totalDeleted} {$table} row(s).");
    }
}
