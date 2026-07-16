<?php

declare(strict_types=1);

namespace Kraite\Core\Contracts;

interface ProductionDatabaseCloneGateway
{
    /** @return list<string> */
    public function productionMigrationNames(): array;

    /** @return list<string> */
    public function productionTableNames(): array;

    /** @param list<string> $tables */
    public function createProductionDump(array $tables, string $remotePath): void;

    public function downloadDump(string $remotePath, string $localPath): void;

    /** @param list<string> $tables */
    public function replaceLocalTables(array $tables, string $localPath): void;

    public function deleteProductionDump(string $remotePath): void;

    public function deleteLocalDump(string $localPath): void;
}
