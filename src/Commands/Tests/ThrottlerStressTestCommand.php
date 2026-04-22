<?php

declare(strict_types=1);

namespace Kraite\Core\Commands\Tests;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kraite\Core\Jobs\Testing\StressTestCmcJob;
use Kraite\Core\Jobs\Testing\StressTestTaapiJob;
use StepDispatcher\Models\Step;

/**
 * Fires N identical API-call steps against a single API system in a single
 * burst, then lets the dispatcher + throttler drain them. Used to measure
 * how quickly the throttler recovers from a saturated queue without
 * interference from other workflows.
 *
 * The command truncates operational tables and clears the logs folder before
 * dispatching so each run starts from a clean baseline. All steps share the
 * same index so they are all dispatchable from the first tick.
 */
final class ThrottlerStressTestCommand extends Command
{
    protected $signature = 'kraite:test-throttler-stress
                            {--count=1000 : Step count(s) — single value applies to all APIs, or comma-separated to match --api positions}
                            {--api=taapi : API system(s) to stress, comma-separated (taapi,cmc)}';

    protected $description = 'Fire N identical API-call steps to isolate the throttler under load.';

    public function handle(): int
    {
        $apis = array_filter(array_map('trim', explode(',', (string) $this->option('api'))));
        $counts = array_filter(array_map('trim', explode(',', (string) $this->option('count'))));

        $jobMap = [
            'taapi' => StressTestTaapiJob::class,
            'cmc' => StressTestCmcJob::class,
        ];

        foreach ($apis as $api) {
            if (! isset($jobMap[$api])) {
                $this->error("Unsupported --api={$api}. Available: ".implode(', ', array_keys($jobMap)));

                return self::FAILURE;
            }
        }

        // Broadcast a single --count value across all APIs; otherwise require
        // one count per API to keep the mapping unambiguous.
        if (count($counts) === 1) {
            $counts = array_fill(0, count($apis), (int) $counts[0]);
        } elseif (count($counts) !== count($apis)) {
            $this->error('--count must be a single value or match the number of --api entries.');

            return self::FAILURE;
        } else {
            $counts = array_map('intval', $counts);
        }

        $perApi = array_combine($apis, $counts);
        $totalSteps = array_sum($counts);

        $this->info('Cleaning operational tables and logs...');

        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        DB::table('steps')->truncate();
        DB::table('steps_archive')->truncate();
        DB::table('steps_dispatcher_ticks')->truncate();
        DB::table('api_request_logs')->truncate();
        DB::table('model_logs')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        cleanLogsFolder();

        $breakdown = array_map(static fn ($api) => "{$api}={$perApi[$api]}", $apis);
        $this->info("Dispatching {$totalSteps} stress steps (".implode(', ', $breakdown).')...');

        $startedAt = microtime(true);

        // Each step gets its own block_uuid so the observer's group assignment
        // falls through to round-robin. This spreads the burst across every
        // dispatcher group for maximum parallelism — exactly the scenario we
        // want the throttler to push back on. When multiple APIs are fired in
        // one invocation, their steps interleave into the same queue so each
        // throttler is exercised in parallel.
        DB::transaction(static function () use ($perApi, $jobMap): void {
            foreach ($perApi as $api => $count) {
                $jobClass = $jobMap[$api];

                for ($i = 1; $i <= $count; $i++) {
                    Step::create([
                        'class' => $jobClass,
                        'queue' => 'cronjobs',
                        'arguments' => ['iteration' => $i],
                        'block_uuid' => (string) Str::uuid(),
                        'index' => 1,
                    ]);
                }
            }
        });

        $elapsed = round(microtime(true) - $startedAt, 2);

        $this->info("Done. {$totalSteps} steps created in {$elapsed}s.");
        $this->line('');
        $this->line('Monitor progress:');
        $this->line('  SELECT state, COUNT(*) FROM steps GROUP BY state;');
        $this->line('  SELECT http_response_code, COUNT(*) FROM api_request_logs GROUP BY http_response_code;');

        return self::SUCCESS;
    }
}
