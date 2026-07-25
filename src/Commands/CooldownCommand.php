<?php

declare(strict_types=1);

namespace Kraite\Core\Commands;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Redis;
use Kraite\Core\Support\MaintenanceMode;
use Kraite\Core\Support\StepRouter;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Dispatched;
use StepDispatcher\States\Running;
use StepDispatcher\Support\BaseCommand;
use StepDispatcher\Support\Steps;
use Throwable;

final class CooldownCommand extends BaseCommand
{
    private const QUEUE_NAMES = ['default', 'priority', 'positions', 'orders', 'cronjobs', 'indicators', 'user-data-stream'];

    protected $signature = 'kraite:cooldown
        {--status : Check cooldown status without initiating}
        {--force : Force immediate cooldown, killing remaining work}';

    protected $description = 'Cooldown this server for deployment. Behaviour depends on SERVER_ROLE env.';

    public function __construct(private readonly StepRouter $stepRouter)
    {
        parent::__construct();
    }

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
            if ($queueDepth === -1) {
                $reasons[] = 'Queue depth: UNKNOWN (Redis probe failed — fail-closed)';
            } elseif ($queueDepth > 0) {
                $reasons[] = "Queue depth: {$queueDepth} jobs pending or in-flight";
            }
        }

        if ($role === 'ingestion') {
            if (! MaintenanceMode::isStepsDispatchPaused(null)) {
                $reasons[] = 'Step dispatcher is ACTIVE (not paused)';
            }

            $activeSteps = $this->getActiveStepCount();
            if ($activeSteps === -1) {
                $reasons[] = 'Active steps: UNKNOWN (DB probe failed — fail-closed)';
            } elseif ($activeSteps > 0) {
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
        $this->info('Step dispatchers paused. No new steps will be created.');

        if ($this->option('force')) {
            Artisan::call('down');
            $this->warn('Force mode: app is DOWN, skipping drain wait.');
            $this->info('STATUS:COOLED_DOWN (forced)');

            return 0;
        }

        $this->line('Waiting for active steps + queues to drain (Horizon still processing)...');
        $maxWait = 300;
        $waited = 0;

        while ($waited < $maxWait) {
            $activeSteps = $this->getActiveStepCount();
            $queueDepth = $this->getQueueDepth();

            if ($activeSteps === 0 && $queueDepth === 0) {
                $this->info("Drained in {$waited}s.");
                $this->line('Putting application into maintenance mode...');
                Artisan::call('down');
                $this->info('Application is DOWN.');
                $this->info('STATUS:COOLED_DOWN');

                return 0;
            }

            $this->line("  Waiting... steps={$activeSteps} queues={$queueDepth} ({$waited}s/{$maxWait}s)");
            sleep(5);
            $waited += 5;
        }

        $this->line('Putting application into maintenance mode...');
        Artisan::call('down');
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

    /**
     * Ready and reserved queue workload probe. Delayed work is intentionally
     * excluded because self-rescheduling jobs may remain delayed throughout a
     * deployment. Returns -1 on probe failure so callers can distinguish
     * "I cannot prove queues are empty" from "queues are empty".
     */
    private function getQueueDepth(): int
    {
        try {
            $depth = 0;
            $redis = Redis::connection();

            foreach ($this->queueNames() as $queue) {
                $depth += (int) $redis->llen("queues:{$queue}");
                $depth += (int) $redis->zcard("queues:{$queue}:reserved");
            }

            return $depth;
        } catch (Throwable $e) {
            $this->warn('Queue-depth probe failed: '.$e->getMessage().' — treating as UNKNOWN (fail-closed).');

            return -1;
        }
    }

    /**
     * Every physical Redis queue Horizon can consume, plus legacy raw queues
     * that may still contain work from an older deployment.
     *
     * @return list<string>
     */
    private function queueNames(): array
    {
        $logicalQueues = self::QUEUE_NAMES;

        foreach ((array) config('kraite.horizon.workers', []) as $queues) {
            if (is_array($queues)) {
                $logicalQueues = [...$logicalQueues, ...array_keys($queues)];
            }
        }

        $physicalQueues = array_map('strval', array_unique($logicalQueues));

        foreach (array_unique($logicalQueues) as $logicalQueue) {
            $physicalQueues = [
                ...$physicalQueues,
                ...$this->stepRouter->physicalQueuesFor((string) $logicalQueue),
            ];
        }

        return array_values(array_unique($physicalQueues));
    }

    /**
     * Active step count probe. Returns -1 on probe failure so callers
     * can distinguish "I cannot prove steps are drained" from "steps
     * are drained". Pre-fix, a DB outage returned 0 — letting cooldown
     * declare drained against unknown step state.
     */
    private function getActiveStepCount(): int
    {
        try {
            // A populated parent is orchestration state, not executable work.
            // Its active descendants are counted independently. Including the
            // parent deadlocks cooldown after dispatch is paused because its
            // pending child tree can no longer advance during the drain.
            $count = static fn (): int => (int) Step::query()
                ->whereIn('state', [Running::class, Dispatched::class])
                ->whereNull('child_block_uuid')
                ->count();

            return $count() + (int) Steps::usingPrefix('trading', $count);
        } catch (Throwable $e) {
            $this->warn('Active-step probe failed: '.$e->getMessage().' — treating as UNKNOWN (fail-closed).');

            return -1;
        }
    }
}
