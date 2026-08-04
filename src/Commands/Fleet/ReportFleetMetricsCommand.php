<?php

declare(strict_types=1);

namespace Kraite\Core\Commands\Fleet;

use Illuminate\Console\Command;
use Kraite\Core\Jobs\Fleet\ReportFleetMetricsJob;
use Kraite\Core\Support\Fleet\FleetMetricsCollector;
use Kraite\Core\Support\Fleet\FleetMetricsRepository;

/**
 * Write the current host's fleet-metrics heartbeat.
 *
 * The ingestion Laravel schedule invokes the synchronous path every five
 * minutes. The seed option queues one immediate snapshot during warmup.
 */
final class ReportFleetMetricsCommand extends Command
{
    protected $signature = 'kraite:fleet-report {--seed : Queue one immediate heartbeat snapshot instead of writing synchronously}';

    protected $description = 'Write this host\'s fleet-metrics snapshot to Redis, or queue one immediate snapshot.';

    public function handle(FleetMetricsCollector $collector, FleetMetricsRepository $repository): int
    {
        $hostname = ReportFleetMetricsJob::resolveHostname();

        if ($hostname === '' || $hostname === 'unknown') {
            $this->error('Could not resolve hostname; aborting.');

            return self::FAILURE;
        }

        if ($this->option('seed')) {
            ReportFleetMetricsJob::seed($hostname);
            $this->info("Fleet-metrics heartbeat queued for {$hostname}.");

            return self::SUCCESS;
        }

        $repository->write($hostname, $collector->collect($hostname, config('kraite.server_role')));
        $this->info("Fleet-metrics snapshot written for {$hostname}.");

        return self::SUCCESS;
    }
}
