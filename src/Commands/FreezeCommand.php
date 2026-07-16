<?php

declare(strict_types=1);

namespace Kraite\Core\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Kraite\Core\Support\FreezeMode;
use Kraite\Core\Support\MaintenanceMode;
use Throwable;

final class FreezeCommand extends Command
{
    protected $signature = 'kraite:freeze';

    protected $aliases = ['kraite:freze'];

    protected $description = 'Freeze all Kraite automation and external traffic while preserving local UI and data access.';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Freeze refused: this command only runs in local or testing environments.');

            return self::FAILURE;
        }

        try {
            FreezeMode::activate();

            MaintenanceMode::pauseStepsDispatch(
                reason: 'kraite:freeze persistent local snapshot mode',
                ttlSeconds: 315_360_000,
            );

            if (! app()->environment('testing')) {
                Artisan::call('horizon:pause');
            }
        } catch (Throwable $exception) {
            $this->error('Freeze activation failed: '.$exception->getMessage());
            $this->warn(FreezeMode::isActive()
                ? 'Marker exists: external traffic remains blocked.'
                : 'Marker missing: system freeze is not active.');

            return self::FAILURE;
        }

        $this->info('STATUS:FROZEN');
        $this->line('Schedules, queue processing, dispatchers, WebSockets, mail, notifications, and outbound API traffic are blocked.');
        $this->line('Local UI and local data edits remain available.');

        return self::SUCCESS;
    }
}
