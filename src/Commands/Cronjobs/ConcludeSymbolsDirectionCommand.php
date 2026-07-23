<?php

declare(strict_types=1);

namespace Kraite\Core\Commands\Cronjobs;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Kraite\Core\Jobs\Models\ExchangeSymbol\ConcludeSymbolDirectionAtTimeframeJob;
use Kraite\Core\Jobs\Models\Indicator\QuerySymbolIndicatorsJob;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Kraite;
use StepDispatcher\Models\Step;
use StepDispatcher\Models\StepsDispatcher;
use StepDispatcher\Support\BaseCommand;
use Str;

final class ConcludeSymbolsDirectionCommand extends BaseCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kraite:cron-conclude-symbols-direction
                            {--clean : Truncate steps, api_request_logs, application_logs, and indicator_histories tables before running}
                            {--reset : Reset all exchange symbols to default state (direction=NULL, clear all flags)}
                            {--output : Display command output (silent by default)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Triggers atomic workflow to conclude trading direction for all exchange symbols. Runs hourly at :10 to allow candles to settle.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if ($this->option('clean') && ! app()->environment(['local', 'testing'])) {
            $this->error(sprintf(
                '[CONCLUDE-SYMBOLS-DIRECTION] --clean refused: current environment is "%s". This flag only runs in local or testing.',
                app()->environment()
            ));

            return self::FAILURE;
        }

        $lock = Cache::lock('kraite:conclude-symbols-direction', 600);

        if (! $lock->get()) {
            $this->verboseInfo('Another symbols-direction pass is already running; skipped.');

            return self::SUCCESS;
        }

        try {
            return $this->executeLocked();
        } finally {
            $lock->release();
        }
    }

    private function executeLocked(): int
    {
        // Truncate tables if --clean flag is provided
        if ($this->option('clean')) {
            $this->verboseInfo('Truncating steps, api_request_logs, indicator_histories tables...');

            DB::statement('SET FOREIGN_KEY_CHECKS=0;');

            DB::table('steps')->truncate();
            DB::table('api_request_logs')->truncate();
            DB::table('indicator_histories')->truncate();
            DB::table('forbidden_hostnames')->truncate();
            DB::table('notification_logs')->truncate();

            DB::statement('SET FOREIGN_KEY_CHECKS=1;');

            $this->verboseInfo('✓ Tables truncated');

            cleanLogsFolder();
            $this->verboseInfo('✓ All logs and log directories cleared');

            $this->verboseNewLine();
        }

        $this->verboseInfo('Processing ALL exchange symbols');

        // Build the query for exchange symbols with their apiSystem for timeframes
        $symbolsToProcess = ExchangeSymbol::needsIndicatorAttempt()
            ->with('apiSystem')
            ->orderBy('id')
            ->get();

        if ($symbolsToProcess->isEmpty()) {
            $this->verboseError('No exchange symbols found in the database.');

            return self::FAILURE;
        }

        $this->verboseInfo("Total symbols: {$symbolsToProcess->count()}");
        $this->verboseNewLine();

        // Reset all exchange symbols if --reset flag is provided
        if ($this->option('reset')) {
            $this->verboseInfo('Resetting all exchange symbols...');

            $resetCount = ExchangeSymbol::query()->update([
                'has_no_indicator_data' => false,
                'has_price_trend_misalignment' => false,
                'has_early_direction_change' => false,
                'has_invalid_indicator_direction' => false,
                'direction' => null,
                'indicators_values' => null,
                'indicators_timeframe' => null,
                'indicators_synced_at' => null,
                'pivot_r3' => null,
                'pivot_r2' => null,
                'pivot_r1' => null,
                'pivot_p' => null,
                'pivot_s1' => null,
                'pivot_s2' => null,
                'pivot_s3' => null,
                'pivot_synced_at' => null,
            ]);

            $this->verboseInfo("✓ Reset {$resetCount} exchange symbols");
            $this->verboseNewLine();
        }

        $progressBar = $this->shouldOutput() ? $this->output->createProgressBar($symbolsToProcess->count()) : null;
        $progressBar?->start();

        // Track symbols skipped due to missing timeframes
        $skippedCount = 0;

        // Wrap workflow creation in transaction to prevent race conditions
        // if command runs concurrently
        DB::transaction(function () use ($symbolsToProcess, $progressBar, &$skippedCount) {
            $timeframes = Kraite::timeframes();

            if (empty($timeframes)) {
                $skippedCount = $symbolsToProcess->count();
                $progressBar?->advance($skippedCount);

                return;
            }

            foreach ($symbolsToProcess as $exchangeSymbol) {

                $startingTimeframe = $timeframes[0];
                $this->createWorkflowForSymbol($exchangeSymbol->id, $startingTimeframe);
                $progressBar?->advance();
            }
        });

        $progressBar?->finish();
        $this->verboseNewLine(2);

        $processedCount = $symbolsToProcess->count() - $skippedCount;
        $this->verboseInfo("✓ Workflows initiated for {$processedCount} symbols successfully!");

        if ($skippedCount > 0) {
            $this->verboseInfo("  - Skipped {$skippedCount} symbols (exchange has no timeframes configured)");
        }

        $this->verboseInfo('  - Each symbol will progress through timeframes conditionally');

        return self::SUCCESS;
    }

    /**
     * Create workflow for a single symbol
     * Only creates Query and Conclude steps upfront.
     * Finalization steps will be created by ConcludeSymbolDirectionAtTimeframeJob if it concludes.
     */
    private function createWorkflowForSymbol(int $symbolId, string $startingTimeframe): string
    {
        $blockUuid = Str::uuid()->toString();
        $group = StepsDispatcher::getNextGroup();
        $now = now();

        // INDEX 1: QuerySymbolIndicatorsJob
        Step::create([
            'class' => QuerySymbolIndicatorsJob::class,
            'queue' => 'indicators',
            'block_uuid' => $blockUuid,
            'group' => $group,
            'index' => 1,
            'arguments' => [
                'exchangeSymbolId' => $symbolId,
                'timeframe' => $startingTimeframe,
                'previousConclusions' => [],
            ],
        ]);

        // INDEX 2: ConcludeSymbolDirectionAtTimeframeJob
        Step::create([
            'class' => ConcludeSymbolDirectionAtTimeframeJob::class,
            'queue' => 'indicators',
            'block_uuid' => $blockUuid,
            'group' => $group,
            'index' => 2,
            'arguments' => [
                'exchangeSymbolId' => $symbolId,
                'timeframe' => $startingTimeframe,
                'previousConclusions' => [],
            ],
        ]);

        // Finalization steps are created dynamically
        // by ConcludeSymbolDirectionAtTimeframeJob only when a direction is successfully concluded

        return $blockUuid;
    }
}
