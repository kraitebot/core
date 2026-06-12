<?php

declare(strict_types=1);

namespace Kraite\Core\Commands\Fleet;

use Illuminate\Console\Command;
use Kraite\Core\Jobs\Fleet\ReportFleetMetricsJob;
use Kraite\Core\Support\Fleet\FleetMetricsCollector;
use Kraite\Core\Support\Fleet\FleetMetricsRepository;

/**
 * Manual entry point for the fleet-metrics heartbeat on this box.
 *
 *  - `--seed` starts (or revives) the self-rescheduling loop by dispatching
 *    one {@see ReportFleetMetricsJob} onto this box's own queue. Idempotent —
 *    the job's uniqueness lock prevents a second loop. Called from warmup; also
 *    the operator's "kick the heartbeat" lever.
 *  - default (no flag) writes a single snapshot synchronously. Useful on a dev
 *    box without a running Horizon, and for verifying the key shape.
 *
 * hyperion does NOT use this command (no PHP app) — it writes the same key via
 * a standalone bash + systemd timer agent.
 */
final class ReportFleetMetricsCommand extends Command
{
    protected $signature = 'kraite:fleet-report {--seed : Dispatch the self-rescheduling heartbeat loop instead of writing once}';

    protected $description = 'Write this box\'s fleet-metrics snapshot to Redis, or --seed the heartbeat loop.';

    public function handle(FleetMetricsCollector $collector, FleetMetricsRepository $repository): int
    {
        $hostname = ReportFleetMetricsJob::resolveHostname();

        if ($hostname === '' || $hostname === 'unknown') {
            $this->error('Could not resolve hostname; aborting.');

            return self::FAILURE;
        }

        if ($this->option('seed')) {
            ReportFleetMetricsJob::seed($hostname);
            $this->info("Fleet-metrics heartbeat seeded for {$hostname}.");

            return self::SUCCESS;
        }

        $repository->write($hostname, $collector->collect($hostname, config('kraite.server_role')));
        $this->info("Fleet-metrics snapshot written for {$hostname}.");

        return self::SUCCESS;
    }
}
