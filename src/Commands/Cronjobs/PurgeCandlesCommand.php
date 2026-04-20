<?php

declare(strict_types=1);

namespace Kraite\Core\Commands\Cronjobs;

use Illuminate\Support\Facades\DB;
use StepDispatcher\Support\BaseCommand;

/**
 * PurgeCandlesCommand
 *
 * Purges old candles beyond the correlation window size.
 * Keeps the most recent N candles per exchange_symbol_id + timeframe combination.
 *
 * Default retention is based on kraite.correlation.window_size (500).
 */
final class PurgeCandlesCommand extends BaseCommand
{
    protected $signature = 'kraite:purge-candles
                            {--keep= : Number of candles to keep per symbol/timeframe (default: correlation.window_size)}
                            {--timeframe= : Only purge a specific timeframe (e.g., 5m, 1h)}
                            {--dry-run : Show what would be deleted without deleting}
                            {--output : Display command output (silent by default)}';

    protected $description = 'Purge old candles beyond the correlation window size';

    public function handle(): int
    {
        $keepCount = (int) ($this->option('keep') ?: config('kraite.correlation.window_size', 500));
        $timeframeFilter = $this->option('timeframe');
        $dryRun = $this->option('dry-run');

        $this->verboseInfo("Purging candles older than the most recent {$keepCount} per symbol/timeframe...");

        if ($dryRun) {
            $this->verboseWarn('DRY RUN - No data will be deleted');
        }

        // Get distinct symbol/timeframe combinations
        $query = DB::table('candles')
            ->select('exchange_symbol_id', 'timeframe')
            ->groupBy('exchange_symbol_id', 'timeframe');

        if ($timeframeFilter) {
            $query->where('timeframe', $timeframeFilter);
        }

        $combinations = $query->get();

        $this->verboseInfo("Found {$combinations->count()} symbol/timeframe combinations");

        $totalDeleted = 0;
        $combinationsProcessed = 0;

        foreach ($combinations as $combo) {
            // Find the timestamp threshold (the Nth most recent candle)
            $threshold = DB::table('candles')
                ->where('exchange_symbol_id', $combo->exchange_symbol_id)
                ->where('timeframe', $combo->timeframe)
                ->orderBy('timestamp', 'desc')
                ->skip($keepCount)
                ->value('timestamp');

            // If no threshold, we have fewer candles than keepCount - nothing to delete
            if ($threshold === null) {
                continue;
            }

            // Count and delete candles older than threshold
            $deleteQuery = DB::table('candles')
                ->where('exchange_symbol_id', $combo->exchange_symbol_id)
                ->where('timeframe', $combo->timeframe)
                ->where('timestamp', '<=', $threshold);

            $count = $deleteQuery->count();

            if ($count > 0) {
                if (! $dryRun) {
                    $deleteQuery->delete();
                }

                $totalDeleted += $count;
                $combinationsProcessed++;
            }
        }

        if ($dryRun) {
            $this->verboseInfo("Would delete {$totalDeleted} candles from {$combinationsProcessed} symbol/timeframe combinations");
        } else {
            $this->verboseInfo("Deleted {$totalDeleted} candles from {$combinationsProcessed} symbol/timeframe combinations");
        }

        return self::SUCCESS;
    }
}
