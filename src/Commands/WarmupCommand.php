<?php

declare(strict_types=1);

namespace Kraite\Core\Commands;

use Illuminate\Support\Facades\Artisan;
use Kraite\Core\Support\MaintenanceMode;
use StepDispatcher\Support\BaseCommand;

final class WarmupCommand extends BaseCommand
{
    protected $signature = 'kraite:warmup';

    protected $description = 'Bring this server back online after a cooldown/deployment.';

    public function handle(): int
    {
        $role = config('kraite.server_role', 'web');

        $this->info("Warming up {$role} server...");

        if ($role === 'ingestion') {
            $this->line('Resuming step dispatchers (all prefixes)...');
            MaintenanceMode::resumeStepsDispatch(null);
            $this->info('Step dispatchers resumed.');
        }

        $this->line('Bringing application UP...');
        Artisan::call('up');
        $this->info('Application is UP.');

        $this->info('STATUS:ONLINE');

        return 0;
    }
}
