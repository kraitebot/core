<?php

declare(strict_types=1);

namespace Kraite\Core\Commands;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Kraite\Core\Jobs\Fleet\ReportFleetMetricsJob;
use Kraite\Core\Support\MaintenanceMode;
use StepDispatcher\Support\BaseCommand;

final class WarmupCommand extends BaseCommand
{
    /**
     * Long-lived ingestion processes that must load the deployed code before
     * the application accepts traffic again.
     *
     * @var list<string>
     */
    private const INGESTION_SUPERVISOR_UNITS = [
        'kraite-horizon',
        'kraite-stream-binance-prices',
        'kraite-stream-binance-user-data',
        'kraite-dispatch-daemon',
        'kraite-scheduler',
    ];

    protected $signature = 'kraite:warmup';

    protected $description = 'Bring this server back online after a cooldown/deployment.';

    public function handle(): int
    {
        $role = config('kraite.server_role', 'web');

        $this->info("Warming up {$role} server...");

        if ($role === 'ingestion') {
            if (! $this->restartIngestionSupervisors()) {
                return self::FAILURE;
            }

            $this->line('Resuming step dispatchers (all prefixes)...');
            // Pre-fix this called resumeStepsDispatch(null), which only
            // forgets the blanket key — a prefix-specific pause (e.g.
            // `trading_steps` paused via pauseStepsDispatch('trading'))
            // would survive warmup and leave that part of the system
            // paused silently. Use the all-prefix helper so warmup
            // returns the full system to dispatching.
            MaintenanceMode::resumeAllStepsDispatch();
            $this->info('Step dispatchers resumed (default + trading).');
        }

        $this->line('Bringing application UP...');
        Artisan::call('up');
        $this->info('Application is UP.');

        if ($role === 'ingestion') {
            MaintenanceMode::startPostWarmupRecovery();
            $minutes = (int) (MaintenanceMode::POST_WARMUP_RECOVERY_SECONDS / 60);
            $this->info("Post-warmup data recovery grace active for {$minutes} minutes.");
        }

        // Publish one immediate fleet-metrics heartbeat. The ingestion
        // scheduler owns all later five-minute ticks.
        $hostname = ReportFleetMetricsJob::resolveHostname();
        if ($hostname !== '' && $hostname !== 'unknown') {
            ReportFleetMetricsJob::seed($hostname);
            $this->info("Fleet-metrics heartbeat queued for {$hostname}.");
        }

        $this->info('STATUS:ONLINE');

        return 0;
    }

    private function restartIngestionSupervisors(): bool
    {
        foreach (self::INGESTION_SUPERVISOR_UNITS as $unit) {
            $result = Process::run([
                'sudo',
                '-n',
                'supervisorctl',
                'restart',
                $unit,
            ]);

            if (! $result->successful()) {
                $this->error("Could not restart {$unit}; application remains in maintenance mode.");

                return false;
            }

            $this->info("Restarted {$unit}.");
        }

        return true;
    }
}
