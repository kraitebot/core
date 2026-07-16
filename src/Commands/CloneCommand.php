<?php

declare(strict_types=1);

namespace Kraite\Core\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Kraite\Core\Actions\CloneProductionDatabase;
use Kraite\Core\Exceptions\MigrationMismatchException;
use Kraite\Core\Support\FreezeMode;
use Throwable;

final class CloneCommand extends Command
{
    protected $signature = 'kraite:clone';

    protected $description = 'Replace cloneable local tables with a production snapshot while preserving large local operational tables.';

    public function handle(CloneProductionDatabase $clone): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Clone refused: this command only runs in local or testing environments.');

            return self::FAILURE;
        }

        if (! FreezeMode::isActive()) {
            $this->error('Clone refused: run kraite:freeze first.');

            return self::FAILURE;
        }

        $this->info('Checking local and production migration parity...');

        $lock = Cache::lock('kraite:clone', 14_400);
        if (! $lock->get()) {
            $this->error('Clone refused: another clone operation is already running.');

            return self::FAILURE;
        }

        try {
            $result = $clone->handle(fn (string $message) => $this->line($message));
        } catch (MigrationMismatchException $exception) {
            $this->error('Migration mismatch: clone aborted before dump or import.');

            foreach ($exception->onlyLocal as $migration) {
                $this->line('  - local only: '.$migration);
            }

            foreach ($exception->onlyProduction as $migration) {
                $this->line('  - production only: '.$migration);
            }

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error('Clone failed: '.$exception->getMessage());
            $this->warn('Local data may be partially updated. Kraite remains frozen.');

            return self::FAILURE;
        } finally {
            $lock->release();
        }

        $this->info('Clone complete: '.count($result['tables']).' local tables replaced from production.');
        $this->line('Nine large operational/history tables were preserved locally.');
        $this->line('Temporary production and local dump files were deleted.');
        $this->line('STATUS:FROZEN');

        return self::SUCCESS;
    }
}
