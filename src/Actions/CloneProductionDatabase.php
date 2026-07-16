<?php

declare(strict_types=1);

namespace Kraite\Core\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Kraite\Core\Contracts\ProductionDatabaseCloneGateway;
use Kraite\Core\Exceptions\MigrationMismatchException;
use Kraite\Core\Support\FreezeMode;
use RuntimeException;
use Throwable;

final class CloneProductionDatabase
{
    /** @var list<string> */
    public const EXCLUDED_TABLES = [
        'indicator_histories',
        'api_request_logs',
        'steps_archive',
        'steps',
        'candles',
        'model_logs',
        'trading_steps_archive',
        'steps_dispatcher_saturation',
        'trading_steps',
    ];

    private const MAX_MIGRATIONS = 10_000;

    public function __construct(
        private readonly ProductionDatabaseCloneGateway $gateway,
    ) {}

    /**
     * @param  (callable(string): void)|null  $progress
     * @return array{tables: list<string>, remote_path: string, local_path: string}
     */
    public function handle(?callable $progress = null): array
    {
        if (! FreezeMode::isActive()) {
            throw new RuntimeException('Kraite must be frozen before cloning production data.');
        }

        $this->verifyMigrationParity();
        if ($progress !== null) {
            $progress('Migration parity confirmed.');
        }

        $tables = $this->includedProductionTables();
        if ($tables === []) {
            throw new RuntimeException('Production returned no cloneable tables.');
        }

        $identifier = now()->format('Ymd_His').'-'.Str::uuid()->toString();
        $remotePath = mb_rtrim($this->requiredConfig('kraite.clone.remote_dump_directory'), '/')."/kraite-clone-{$identifier}.sql.gz";
        $localPath = mb_rtrim($this->requiredConfig('kraite.clone.local_dump_directory'), '/')."/kraite-clone-{$identifier}.sql.gz";

        $failure = null;
        $cleanupFailures = [];

        try {
            if ($progress !== null) {
                $progress('Creating production dump for '.count($tables).' tables...');
            }
            $this->gateway->createProductionDump($tables, $remotePath);
            if ($progress !== null) {
                $progress('Copying production dump to localhost...');
            }
            $this->gateway->downloadDump($remotePath, $localPath);
            if ($progress !== null) {
                $progress('Replacing cloneable local tables...');
            }
            $this->gateway->replaceLocalTables($tables, $localPath);
        } catch (Throwable $exception) {
            $failure = $exception;
        } finally {
            if ($progress !== null) {
                $progress('Deleting temporary dump files...');
            }

            try {
                $this->gateway->deleteProductionDump($remotePath);
            } catch (Throwable $exception) {
                $cleanupFailures[] = 'production: '.$exception->getMessage();
            }

            try {
                $this->gateway->deleteLocalDump($localPath);
            } catch (Throwable $exception) {
                $cleanupFailures[] = 'localhost: '.$exception->getMessage();
            }
        }

        if ($failure !== null) {
            throw $failure;
        }

        if ($cleanupFailures !== []) {
            throw new RuntimeException('Clone imported, but temporary dump cleanup failed: '.implode('; ', $cleanupFailures));
        }

        return [
            'tables' => $tables,
            'remote_path' => $remotePath,
            'local_path' => $localPath,
        ];
    }

    private function verifyMigrationParity(): void
    {
        $localMigrations = $this->localMigrationNames();
        $productionMigrations = $this->normaliseNames($this->gateway->productionMigrationNames(), 'migration');

        $onlyLocal = array_values(array_diff($localMigrations, $productionMigrations));
        $onlyProduction = array_values(array_diff($productionMigrations, $localMigrations));

        if ($onlyLocal !== [] || $onlyProduction !== []) {
            throw new MigrationMismatchException($onlyLocal, $onlyProduction);
        }
    }

    /** @return list<string> */
    private function localMigrationNames(): array
    {
        $count = DB::table('migrations')->count();

        if ($count > self::MAX_MIGRATIONS) {
            throw new RuntimeException('Local migrations table exceeds the clone safety limit.');
        }

        $names = DB::table('migrations')
            ->orderBy('migration')
            ->limit(self::MAX_MIGRATIONS)
            ->pluck('migration')
            ->all();

        if (count($names) !== $count) {
            throw new RuntimeException('Could not read the complete local migration history.');
        }

        return $this->normaliseNames($names, 'migration');
    }

    /** @return list<string> */
    private function includedProductionTables(): array
    {
        $productionTables = $this->normaliseNames($this->gateway->productionTableNames(), 'table');
        $includedTables = array_values(array_diff($productionTables, self::EXCLUDED_TABLES));

        sort($includedTables);

        return $includedTables;
    }

    /**
     * @param  array<mixed>  $names
     * @return list<string>
     */
    private function normaliseNames(array $names, string $subject): array
    {
        $normalised = [];

        foreach ($names as $name) {
            if (! is_string($name) || preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
                throw new InvalidArgumentException("Production returned an invalid {$subject} name.");
            }

            $normalised[] = $name;
        }

        $normalised = array_values(array_unique($normalised));
        sort($normalised);

        return $normalised;
    }

    private function requiredConfig(string $key): string
    {
        $value = config($key);

        if (! is_string($value) || $value === '') {
            throw new RuntimeException("Missing required clone configuration [{$key}].");
        }

        return $value;
    }
}
