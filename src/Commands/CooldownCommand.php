<?php

declare(strict_types=1);

namespace Kraite\Core\Commands;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Redis;
use Kraite\Core\Support\MaintenanceMode;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Dispatched;
use StepDispatcher\States\Running;
use StepDispatcher\Support\BaseCommand;

final class CooldownCommand extends BaseCommand
{
    protected $signature = 'kraite:cooldown
        {--status : Check cooldown status without initiating}
        {--force : Force immediate cooldown, killing remaining work}';

    protected $description = 'Cooldown this server for deployment. Behaviour depends on SERVER_ROLE env.';

    private const QUEUE_NAMES = ['default', 'priority', 'positions', 'orders', 'cronjobs', 'indicators', 'user-data-stream'];

    public function handle(): int
    {
        $role = config('kraite.server_role', 'web');

        if ($this->option('status')) {
            return $this->reportStatus($role);
        }

        return match ($role) {
            'ingestion' => $this->cooldownIngestion(),
            'worker' => $this->cooldownWorker(),
            'web' => $this->cooldownWeb(),
            default => $this->cooldownWeb(),
        };
    }

    private function reportStatus(string $role): int
    {
        $reasons = [];

        if (! app()->isDownForMaintenance()) {
            $reasons[] = 'Application is UP (not in maintenance mode)';
        }

        if (in_array($role, ['ingestion', 'worker'])) {
            $queueDepth = $this->getQueueDepth();
            if ($queueDepth > 0) {
                $reasons[] = "Queue depth: {$queueDepth} jobs pending";
            }
        }

        if ($role === 'ingestion') {
            if (! MaintenanceMode::isStepsDispatchPaused(null)) {
                $reasons[] = 'Step dispatcher is ACTIVE (not paused)';
            }

            $activeSteps = $this->getActiveStepCount();
            if ($activeSteps > 0) {
                $reasons[] = "Active steps: {$activeSteps} (running/dispatched)";
            }
        }

        if (empty($reasons)) {
            $this->info('STATUS:COOLED_DOWN');
            $this->line("Role: {$role} — server is fully cooled down and ready for deployment.");

            return 0;
        }

        $this->warn('STATUS:ACTIVE');
        $this->line("Role: {$role}");
        foreach ($reasons as $reason) {
            $this->line("  - {$reason}");
        }

        return 1;
    }

    private function cooldownIngestion(): int
    {
        $this->info('Cooling down INGESTION server...');

        $this->line('Pausing step dispatchers (all prefixes)...');
        MaintenanceMode::pauseStepsDispatch('kraite:cooldown deployment', 1800);
        $this->info('Step dispatchers paused.');

        $this->line('Putting application into maintenance mode...');
        Artisan::call('down');
        $this->info('Application is DOWN.');

        if ($this->option('force')) {
            $this->warn('Force mode: skipping drain wait.');
            $this->info('STATUS:COOLED_DOWN (forced)');

            return 0;
        }

        $this->line('Waiting for active steps to drain...');
        $maxWait = 300;
        $waited = 0;

        while ($waited < $maxWait) {
            $activeSteps = $this->getActiveStepCount();
            $queueDepth = $this->getQueueDepth();

            if ($activeSteps === 0 && $queueDepth === 0) {
                $this->info("Drained in {$waited}s.");
                $this->info('STATUS:COOLED_DOWN');

                return 0;
            }

            $this->line("  Waiting... steps={$activeSteps} queues={$queueDepth} ({$waited}s/{$maxWait}s)");
            sleep(5);
            $waited += 5;
        }

        $this->error("Timeout after {$maxWait}s. Steps={$this->getActiveStepCount()} Queues={$this->getQueueDepth()}");
        $this->error('STATUS:TIMEOUT — run with --force to override, or investigate stuck jobs.');

        return 1;
    }

    private function cooldownWorker(): int
    {
        $this->info('Cooling down WORKER server...');

        $this->line('Putting application into maintenance mode...');
        Artisan::call('down');
        $this->info('Application is DOWN.');

        if ($this->option('force')) {
            $this->warn('Force mode: skipping drain wait.');
            $this->info('STATUS:COOLED_DOWN (forced)');

            return 0;
        }

        $this->line('Waiting for queues to drain...');
        $maxWait = 180;
        $waited = 0;

        while ($waited < $maxWait) {
            $queueDepth = $this->getQueueDepth();

            if ($queueDepth === 0) {
                $this->info("Queues drained in {$waited}s.");
                $this->info('STATUS:COOLED_DOWN');

                return 0;
            }

            $this->line("  Waiting... queues={$queueDepth} ({$waited}s/{$maxWait}s)");
            sleep(5);
            $waited += 5;
        }

        $this->error("Timeout after {$maxWait}s. Queues={$this->getQueueDepth()}");
        $this->error('STATUS:TIMEOUT — run with --force to override.');

        return 1;
    }

    private function cooldownWeb(): int
    {
        $this->info('Cooling down WEB server...');

        Artisan::call('down');
        $this->info('Application is DOWN.');
        $this->info('STATUS:COOLED_DOWN');

        return 0;
    }

    private function getQueueDepth(): int
    {
        try {
            $depth = 0;
            foreach (self::QUEUE_NAMES as $queue) {
                $depth += (int) Redis::connection()->llen("queues:{$queue}");
            }

            $hostname = strtolower(str_replace('-', '', gethostname() ?: 'unknown'));
            $depth += (int) Redis::connection()->llen("queues:{$hostname}");

            return $depth;
        } catch (\Throwable) {
            return 0;
        }
    }

    private function getActiveStepCount(): int
    {
        try {
            return Step::whereIn('state', [Running::class, Dispatched::class])->count();
        } catch (\Throwable) {
            return 0;
        }
    }
}
