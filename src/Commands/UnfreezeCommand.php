<?php

declare(strict_types=1);

namespace Kraite\Core\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Kraite\Core\Support\FreezeMode;
use Kraite\Core\Support\FrozenOperationalData;
use Kraite\Core\Support\MaintenanceMode;
use Throwable;

final class UnfreezeCommand extends Command
{
    protected $signature = 'kraite:unfreeze
        {--force : Delete protected operational data, logs, and queued jobs without asking}';

    protected $description = 'Unfreeze Kraite only after protected local trading state has been verified empty.';

    public function handle(FrozenOperationalData $operationalData): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Unfreeze refused: this command only runs in local or testing environments.');

            return self::FAILURE;
        }

        if (! FreezeMode::isActive()) {
            $this->info('STATUS:UNFROZEN');

            return self::SUCCESS;
        }

        try {
            $state = $operationalData->state();
        } catch (Throwable $exception) {
            $this->error('Unfreeze refused: protected state could not be verified: '.$exception->getMessage());

            return self::FAILURE;
        }

        $mustClean = (bool) $this->option('force');

        if (! $operationalData->isClean($state)) {
            foreach ($operationalData->dirtySummary($state) as $item) {
                $this->line('  - '.$item);
            }

            if (! $mustClean) {
                if (! $this->input->isInteractive()) {
                    $this->error('Unfreeze refused: protected local data exists. Re-run interactively or use --force.');

                    return self::FAILURE;
                }

                if (! $this->confirm('Protected local data exists. Delete it now?')) {
                    $this->warn('Unfreeze cancelled. STATUS:FROZEN');

                    return self::FAILURE;
                }

                $mustClean = true;
            }
        }

        try {
            if ($mustClean) {
                $operationalData->clean();
            }

            $verifiedState = $operationalData->state();
            if (! $operationalData->isClean($verifiedState)) {
                $this->error('Unfreeze refused: cleanup verification failed.');

                foreach ($operationalData->dirtySummary($verifiedState) as $item) {
                    $this->line('  - '.$item);
                }

                return self::FAILURE;
            }

            FreezeMode::deactivate();
            MaintenanceMode::resumeAllStepsDispatch();

            if (! app()->environment('testing')) {
                Artisan::call('horizon:continue');
            }
        } catch (Throwable $exception) {
            FreezeMode::activate();
            MaintenanceMode::pauseStepsDispatch(
                reason: 'kraite:unfreeze failed closed',
                ttlSeconds: 315_360_000,
            );

            $this->error('Unfreeze failed closed: '.$exception->getMessage());
            $this->warn('STATUS:FROZEN');

            return self::FAILURE;
        }

        $this->info('STATUS:UNFROZEN');

        return self::SUCCESS;
    }
}
