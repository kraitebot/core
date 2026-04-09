<?php

declare(strict_types=1);

namespace Kraite\Core\Commands\Debug;

use Exception;
use Illuminate\Support\Facades\DB;
use StepDispatcher\Support\BaseCommand;

final class ClearTablesCommand extends BaseCommand
{
    /**
     * Tables that should NEVER be truncated.
     */
    private const array PROTECTED_TABLES = [
        'accounts',
        'api_systems',
        'exchange_symbols',
        'indicators',
        'engine',
        'migrations',
        'notifications',
        'servers',
        'steps',
        'steps_dispatcher',
        'symbols',
        'trade_configuration',
        'users',
    ];

    protected $signature = 'kraite:debug-clear-tables
                            {--output : Display command output (silent by default)}';

    protected $description = 'Truncate all database tables except whitelisted ones (for debugging/testing)';

    public function handle(): int
    {
        // Hostname validation: Only allow on ingestion or development servers
        $hostname = gethostname();
        $allowedHostnames = ['ingestion', 'DELLXPS15'];

        if (! in_array($hostname, $allowedHostnames, strict: true)) {
            $this->verboseInfo("⏭️  Skipping table truncation (hostname: {$hostname})");
            $this->verboseLine('   This command only runs on: '.implode(separator: ', ', array: $allowedHostnames));

            return self::SUCCESS;
        }

        // Get all table names from the database
        $allTables = $this->getAllTableNames();

        // Filter out protected tables
        /** @var array<int, string> $tablesToTruncate */
        $tablesToTruncate = array_values(array_diff($allTables, self::PROTECTED_TABLES));

        if (empty($tablesToTruncate)) {
            $this->verboseInfo('No tables to truncate. All tables are protected.');

            return self::SUCCESS;
        }

        // Show what will be truncated
        $this->verboseWarn('The following tables will be TRUNCATED:');
        foreach ($tablesToTruncate as $table) {
            $this->verboseLine("  - {$table}");
        }

        $this->verboseNewLine();
        $this->verboseInfo('Protected tables (will NOT be truncated):');
        foreach (self::PROTECTED_TABLES as $table) {
            if (! (in_array($table, $allTables))) {
                continue;
            }

            $this->verboseLine("  - {$table}");
        }

        // Truncate tables
        // Note: TRUNCATE is DDL and implicitly commits, so we can't use DB::transaction()
        $this->verboseNewLine();
        $this->verboseInfo('Truncating tables...');

        try {
            // Disable foreign key constraints
            DB::statement('SET FOREIGN_KEY_CHECKS=0');

            $truncatedCount = 0;

            foreach ($tablesToTruncate as $table) {
                try {
                    DB::table($table)->truncate();
                    $this->verboseLine("✓ Truncated: {$table}");
                    $truncatedCount++;
                } catch (Exception $e) {
                    $this->verboseError("✗ Failed to truncate {$table}: {$e->getMessage()}");
                }
            }

            // Re-enable foreign key constraints
            DB::statement('SET FOREIGN_KEY_CHECKS=1');

            $this->verboseNewLine();
            $this->verboseInfo("Successfully truncated {$truncatedCount} table(s).");
        } catch (Exception $e) {
            $this->verboseError('Truncation failed: '.$e->getMessage());

            // Ensure FK constraints are re-enabled even on failure
            try {
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
            } catch (Exception $e) {
                $this->verboseError('Failed to re-enable foreign key constraints: '.$e->getMessage());
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Get all table names from the current database.
     *
     * @return array<int, string>
     */
    private function getAllTableNames(): array
    {
        $databaseName = DB::getDatabaseName();

        /** @var array<int, object{TABLE_NAME: string}> $tables */
        $tables = DB::select("
            SELECT TABLE_NAME
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = ?
            AND TABLE_TYPE = 'BASE TABLE'
        ", [$databaseName]);

        return array_map(callback: static function (object $table): string {
            return $table->TABLE_NAME;
        }, array: $tables);
    }
}
