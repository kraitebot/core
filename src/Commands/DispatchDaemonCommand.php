<?php

declare(strict_types=1);

namespace Kraite\Core\Commands;

use Illuminate\Console\Command;
use Kraite\Core\Support\FreezeMode;
use Kraite\Core\Support\MaintenanceMode;
use StepDispatcher\Support\StepDispatcher;
use StepDispatcher\Support\Steps;
use Throwable;

final class DispatchDaemonCommand extends Command
{
    private const GROUPS = ['alpha', 'beta', 'gamma', 'delta', 'epsilon', 'zeta', 'eta', 'theta', 'iota', 'kappa'];

    protected $signature = 'kraite:dispatch-daemon
        {--sleep=1000 : Milliseconds between tick cycles}
        {--idle-sleep=500 : Milliseconds when dispatcher is inactive}';

    protected $description = 'Long-running step dispatcher daemon. Replaces 20 scheduler forks with 1 persistent process.';

    public function handle(): int
    {
        $sleepMs = (int) $this->option('sleep');
        $idleSleepMs = (int) $this->option('idle-sleep');

        $this->info('Dispatch daemon started — '.count(self::GROUPS).' groups × 2 prefixes');

        while (true) {
            if (FreezeMode::isActive() || ! config('kraite.can_dispatch_steps')) {
                usleep($idleSleepMs * 1000);

                continue;
            }

            // Idle gate per prefix, against DB truth — NOT the activation
            // flag files. The flags are per-prefix AND per-machine: the old
            // `StepDispatcher::isActive()` gate here ran outside any prefix
            // ambient, so it only ever saw the default `active.flag`. The
            // moment the default prefix drained, the daemon went to sleep
            // with trading steps still Pending — trading work only moved
            // during the seconds after a scheduler cron recreated the
            // default flag, turning every position open/close ladder into
            // a one-hop-per-minute crawl (caught 2026-06-05 during the
            // first live trading smoke test). Worse on the fleet: child
            // steps created on a worker box write the WORKER's local flag
            // file, never athena's. `hasActiveSteps()` is a sub-millisecond
            // EXISTS on the shared DB, so it is both prefix-correct and
            // fleet-correct.
            $defaultActive = StepDispatcher::hasActiveSteps();
            $tradingActive = Steps::usingPrefix('trading', static function (): bool {
                return StepDispatcher::hasActiveSteps();
            });

            if (! $defaultActive && ! $tradingActive) {
                usleep($idleSleepMs * 1000);

                continue;
            }

            if ($defaultActive) {
                $this->tickDefault();
            }

            if ($tradingActive) {
                $this->tickTrading();
            }

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
