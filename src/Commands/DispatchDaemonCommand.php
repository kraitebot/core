<?php

declare(strict_types=1);

namespace Kraite\Core\Commands;

use Illuminate\Console\Command;
use Kraite\Core\Support\MaintenanceMode;
use StepDispatcher\Support\StepDispatcher;
use StepDispatcher\Support\Steps;
use Throwable;

final class DispatchDaemonCommand extends Command
{
    protected $signature = 'kraite:dispatch-daemon
        {--sleep=1000 : Milliseconds between tick cycles}
        {--idle-sleep=500 : Milliseconds when dispatcher is inactive}';

    protected $description = 'Long-running step dispatcher daemon. Replaces 20 scheduler forks with 1 persistent process.';

    private const GROUPS = ['alpha', 'beta', 'gamma', 'delta', 'epsilon', 'zeta', 'eta', 'theta', 'iota', 'kappa'];

    public function handle(): int
    {
        $sleepMs = (int) $this->option('sleep');
        $idleSleepMs = (int) $this->option('idle-sleep');

        $this->info('Dispatch daemon started — ' . count(self::GROUPS) . ' groups × 2 prefixes');

        while (true) {
            if (! config('kraite.can_dispatch_steps')) {
                usleep($idleSleepMs * 1000);

                continue;
            }

            if (! StepDispatcher::isActive()) {
                usleep($idleSleepMs * 1000);

                continue;
            }

            $this->tickDefault();
            $this->tickTrading();

            usleep($sleepMs * 1000);
        }

        return self::SUCCESS;
    }

    private function tickDefault(): void
    {
        if (MaintenanceMode::isStepsDispatchPaused('')) {
            return;
        }

        try {
            foreach (self::GROUPS as $group) {
                StepDispatcher::dispatch($group);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function tickTrading(): void
    {
        if (MaintenanceMode::isStepsDispatchPaused('trading')) {
            return;
        }

        try {
            Steps::usingPrefix('trading', function (): void {
                foreach (self::GROUPS as $group) {
                    StepDispatcher::dispatch($group);
                }
            });
        } catch (Throwable $e) {
            report($e);
        }
    }
}
