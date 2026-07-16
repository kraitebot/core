<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Database;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use JsonException;
use Kraite\Core\Contracts\ProductionDatabaseCloneGateway;
use RuntimeException;

final class SshProductionDatabaseCloneGateway implements ProductionDatabaseCloneGateway
{
    private const OUTPUT_MARKER = 'KRAITE_CLONE_JSON:';

    private const MAX_MIGRATIONS = 10_000;

    private const MAX_TABLES = 1_000;

    private ?string $productionDatabaseName = null;

    public function productionMigrationNames(): array
    {
        $php = <<<'PHP'
$count = Illuminate\Support\Facades\DB::table('migrations')->count();
if ($count > 10000) {
    throw new RuntimeException('Production migrations table exceeds the clone safety limit.');
}
$names = Illuminate\Support\Facades\DB::table('migrations')
    ->orderBy('migration')
    ->limit(10000)
    ->pluck('migration')
    ->values()
    ->all();
$payload = [
    'database' => Illuminate\Support\Facades\DB::connection()->getDatabaseName(),
    'count' => $count,
    'names' => $names,
];
echo 'KRAITE_CLONE_JSON:'.base64_encode(json_encode($payload, JSON_THROW_ON_ERROR));
PHP;

        $payload = $this->queryProduction($php, 'migrations');

        return $this->metadataNames($payload, self::MAX_MIGRATIONS, 'migration');
    }

    public function productionTableNames(): array
    {
        $php = <<<'PHP'
$database = Illuminate\Support\Facades\DB::connection()->getDatabaseName();
$query = Illuminate\Support\Facades\DB::table('information_schema.tables')
    ->where('table_schema', $database)
    ->where('table_type', 'BASE TABLE');
$count = (clone $query)->count();
if ($count > 1000) {
    throw new RuntimeException('Production table count exceeds the clone safety limit.');
}
$rows = $query
    ->orderBy('table_name')
    ->limit(1000)
    ->get(['TABLE_NAME']);
$names = $rows
    ->map(static function (object $row): string {
        $name = $row->TABLE_NAME ?? $row->table_name ?? null;
        if (! is_string($name)) {
            throw new RuntimeException('Production table metadata contained an invalid name.');
        }
        return $name;
    })
    ->all();
$payload = ['database' => $database, 'count' => $count, 'names' => $names];
echo 'KRAITE_CLONE_JSON:'.base64_encode(json_encode($payload, JSON_THROW_ON_ERROR));
PHP;

        $payload = $this->queryProduction($php, 'tables');

        return $this->metadataNames($payload, self::MAX_TABLES, 'table');
    }

    public function createProductionDump(array $tables, string $remotePath): void
    {
        $this->assertTableNames($tables);
        $this->assertRemoteDumpPath($remotePath);

        $database = $this->productionDatabaseName;
        if ($database === null || preg_match('/^[A-Za-z0-9_]+$/', $database) !== 1) {
            throw new RuntimeException('Production database name was not established during preflight.');
        }

        $clientPath = $this->productionClientPath($remotePath);
        $php = $this->writeMySqlClientConfigPhp($clientPath);
        $artisan = $this->remoteArtisanTinkerCommand($php);
        $tableArguments = implode(' ', array_map(escapeshellarg(...), $tables));
        $temporaryDumpPath = $remotePath.'.part';

        $shell = implode(' ', [
            'set -euo pipefail;',
            'umask 077;',
            'mkdir -p '.escapeshellarg(dirname($remotePath)).';',
            'trap '.escapeshellarg('rm -f -- '.escapeshellarg($clientPath).' '.escapeshellarg($temporaryDumpPath)).' EXIT;',
            $artisan.' >/dev/null;',
            'mysqldump',
            '--defaults-extra-file='.escapeshellarg($clientPath),
            '--single-transaction',
            '--quick',
            '--skip-lock-tables',
            '--skip-add-locks',
            '--no-tablespaces',
            '--no-create-info',
            '--skip-triggers',
            '--hex-blob',
            escapeshellarg($database),
            $tableArguments,
            '| gzip -1 > '.escapeshellarg($temporaryDumpPath).';',
            'test -s '.escapeshellarg($temporaryDumpPath).';',
            'mv '.escapeshellarg($temporaryDumpPath).' '.escapeshellarg($remotePath).';',
        ]);

        $this->runSsh('dump', $this->asRemoteAppUser($shell), $this->timeout());
    }

    public function downloadDump(string $remotePath, string $localPath): void
    {
        $this->assertRemoteDumpPath($remotePath);
        $this->assertLocalDumpPath($localPath);

        File::ensureDirectoryExists(dirname($localPath));

        $result = Process::timeout($this->timeout())->run([
            'scp',
            ...$this->sshOptions(),
            $this->sshDestination().':'.$remotePath,
            $localPath,
        ]);

        $this->ensureSuccessful($result, 'download production dump');

        if (! File::isFile($localPath) || File::size($localPath) < 1024) {
            throw new RuntimeException('Downloaded production dump is missing or under 1 KB.');
        }
    }

    public function replaceLocalTables(array $tables, string $localPath): void
    {
        $this->assertTableNames($tables);
        $this->assertLocalDumpPath($localPath);

        if (! File::isFile($localPath)) {
            throw new RuntimeException('Local clone dump is missing before import.');
        }

        $gzipTest = Process::timeout(120)->run(['gzip', '-t', $localPath]);
        $this->ensureSuccessful($gzipTest, 'validate downloaded dump');

        $connectionName = config('database.default');
        $connection = is_string($connectionName) ? config("database.connections.{$connectionName}") : null;

        if (! is_array($connection)) {
            throw new RuntimeException('Local database configuration is unavailable.');
        }

        $database = $connection['database'] ?? null;
        if (! is_string($database) || preg_match('/^[A-Za-z0-9_]+$/', $database) !== 1) {
            throw new RuntimeException('Local database name is invalid for clone import.');
        }

        $clientPath = storage_path('framework/kraite-clone-client-'.Str::uuid()->toString().'.cnf');
        File::ensureDirectoryExists(dirname($clientPath));
        if (File::put($clientPath, $this->mysqlClientConfig($connection)) === false || ! chmod($clientPath, 0o600)) {
            File::delete($clientPath);

            throw new RuntimeException('Unable to create a protected local MySQL client file.');
        }

        $truncateStatements = implode('', array_map(
            static fn (string $table): string => "TRUNCATE TABLE `{$table}`;",
            $tables,
        ));
        $preamble = 'SET SESSION FOREIGN_KEY_CHECKS=0;SET SESSION UNIQUE_CHECKS=0;'.$truncateStatements;
        $shell = 'set -o pipefail; { printf %s '.escapeshellarg($preamble).'; gzip -dc -- '.escapeshellarg($localPath).'; }'
            .' | mysql --defaults-extra-file='.escapeshellarg($clientPath)
            .' --database='.escapeshellarg($database)
            .' --binary-mode=1';

        try {
            $result = Process::timeout($this->timeout())->run(['bash', '-lc', $shell]);
            $this->ensureSuccessful($result, 'replace local tables');
        } finally {
            File::delete($clientPath);
        }
    }

    public function deleteProductionDump(string $remotePath): void
    {
        $this->assertRemoteDumpPath($remotePath);

        $this->runSsh(
            'cleanup-production',
            $this->asRemoteAppUser('rm -f -- '
                .escapeshellarg($remotePath).' '
                .escapeshellarg($remotePath.'.part').' '
                .escapeshellarg($this->productionClientPath($remotePath))),
            120,
        );
    }

    public function deleteLocalDump(string $localPath): void
    {
        $this->assertLocalDumpPath($localPath);

        if (File::exists($localPath) && ! File::delete($localPath)) {
            throw new RuntimeException('Unable to delete the local clone dump.');
        }
    }

    /** @return array<mixed> */
    private function queryProduction(string $php, string $action): array
    {
        $result = $this->runSsh(
            $action,
            $this->asRemoteAppUser($this->remoteArtisanTinkerCommand($php)),
            120,
        );

        if (preg_match('/'.preg_quote(self::OUTPUT_MARKER, '/').'([A-Za-z0-9+\/=]+)/', $result->output(), $matches) !== 1) {
            throw new RuntimeException("Production {$action} probe returned no readable payload.");
        }

        $json = base64_decode($matches[1], strict: true);
        if ($json === false) {
            throw new RuntimeException("Production {$action} payload was not valid base64.");
        }

        try {
            $decoded = json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("Production {$action} payload was invalid.", previous: $exception);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException("Production {$action} payload was not an object.");
        }

        $database = $decoded['database'] ?? null;
        if (! is_string($database) || preg_match('/^[A-Za-z0-9_]+$/', $database) !== 1) {
            throw new RuntimeException('Production database name was invalid.');
        }

        $this->productionDatabaseName = $database;

        return $decoded;
    }

    /**
     * @param  array<mixed>  $payload
     * @return list<string>
     */
    private function metadataNames(array $payload, int $limit, string $subject): array
    {
        $count = $payload['count'] ?? null;
        $names = $payload['names'] ?? null;

        if (! is_int($count) || $count > $limit || ! is_array($names) || count($names) !== $count) {
            throw new RuntimeException("Production {$subject} metadata was incomplete.");
        }

        $result = [];
        foreach ($names as $name) {
            if (! is_string($name)) {
                throw new RuntimeException("Production {$subject} metadata contained a non-string name.");
            }

            $result[] = $name;
        }

        return $result;
    }

    private function remoteArtisanTinkerCommand(string $php): string
    {
        $encoded = base64_encode($php);
        $tinker = 'eval(base64_decode('.var_export($encoded, true).'));';

        return 'cd '.escapeshellarg($this->requiredConfig('kraite.clone.production.project_path'))
            .' && php artisan tinker --execute='.escapeshellarg($tinker);
    }

    private function asRemoteAppUser(string $command): string
    {
        $appUser = $this->requiredConfig('kraite.clone.production.app_user');

        return 'su - '.escapeshellarg($appUser).' -c '.escapeshellarg($command);
    }

    private function runSsh(string $action, string $remoteCommand, int $timeout): ProcessResult
    {
        $taggedRemoteCommand = ': '.escapeshellarg("kraite-clone:{$action}").'; '.$remoteCommand;
        $result = Process::timeout($timeout)->run([
            'ssh',
            ...$this->sshOptions(),
            $this->sshDestination(),
            $taggedRemoteCommand,
        ]);

        $this->ensureSuccessful($result, "run production {$action}");

        return $result;
    }

    /** @return list<string> */
    private function sshOptions(): array
    {
        $identityFile = $this->requiredConfig('kraite.clone.production.identity_file');

        if (! File::isFile($identityFile) || ! is_readable($identityFile)) {
            throw new RuntimeException('Kraite production SSH identity file is missing or unreadable.');
        }

        return [
            '-i', $identityFile,
            '-o', 'IdentitiesOnly=yes',
            '-o', 'BatchMode=yes',
            '-o', 'ConnectTimeout=10',
        ];
    }

    private function sshDestination(): string
    {
        $user = $this->requiredConfig('kraite.clone.production.ssh_user');
        $host = $this->requiredConfig('kraite.clone.production.host');

        if (preg_match('/^[A-Za-z0-9_.-]+$/', $user) !== 1 || preg_match('/^[A-Za-z0-9_.:-]+$/', $host) !== 1) {
            throw new RuntimeException('Kraite production SSH destination is invalid.');
        }

        return "{$user}@{$host}";
    }

    /** @param list<string> $tables */
    private function assertTableNames(array $tables): void
    {
        if ($tables === []) {
            throw new RuntimeException('No tables were supplied for clone processing.');
        }

        foreach ($tables as $table) {
            if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
                throw new RuntimeException('Unsafe table name supplied for clone processing.');
            }
        }
    }

    private function assertRemoteDumpPath(string $path): void
    {
        $directory = mb_rtrim($this->requiredConfig('kraite.clone.remote_dump_directory'), '/').'/';

        if (! str_starts_with($path, $directory) || preg_match('#/kraite-clone-[A-Za-z0-9_-]+\.sql\.gz$#', $path) !== 1) {
            throw new RuntimeException('Unsafe production clone dump path.');
        }
    }

    private function assertLocalDumpPath(string $path): void
    {
        $directory = mb_rtrim($this->requiredConfig('kraite.clone.local_dump_directory'), '/').'/';

        if (! str_starts_with($path, $directory) || preg_match('#/kraite-clone-[A-Za-z0-9_-]+\.sql\.gz$#', $path) !== 1) {
            throw new RuntimeException('Unsafe local clone dump path.');
        }
    }

    private function productionClientPath(string $remotePath): string
    {
        return '/tmp/'.str_replace('.sql.gz', '.cnf', basename($remotePath));
    }

    /** @param array<mixed> $connection */
    private function mysqlClientConfig(array $connection): string
    {
        $lines = ['[client]'];

        foreach (['host', 'port', 'user' => 'username', 'password', 'socket' => 'unix_socket'] as $option => $configKey) {
            if (is_int($option)) {
                $option = $configKey;
            }

            $value = $connection[$configKey] ?? null;
            if ($value === null || $value === '') {
                continue;
            }

            if (! is_scalar($value)) {
                throw new RuntimeException("Local database option [{$configKey}] is not scalar.");
            }

            $lines[] = $option.'='.$this->mysqlOptionValue((string) $value);
        }

        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    private function writeMySqlClientConfigPhp(string $path): string
    {
        $pathExport = var_export($path, true);

        $php = <<<'PHP'
$connectionName = config('database.default');
$connection = config("database.connections.{$connectionName}");
if (! is_array($connection)) {
    throw new RuntimeException('Production database configuration is unavailable.');
}
$quote = static function (mixed $value): string {
    $escaped = str_replace(["\\", "\n", "\r", '"'], ["\\\\", "\\n", "\\r", '\\"'], (string) $value);
    return '"'.$escaped.'"';
};
$lines = ['[client]'];
foreach (['host', 'port', 'user' => 'username', 'password', 'socket' => 'unix_socket'] as $option => $configKey) {
    if (is_int($option)) {
        $option = $configKey;
    }
    $value = $connection[$configKey] ?? null;
    if ($value === null || $value === '') {
        continue;
    }
    if (! is_scalar($value)) {
        throw new RuntimeException("Production database option [{$configKey}] is not scalar.");
    }
    $lines[] = $option.'='.$quote($value);
}
$path = __PATH__;
if (file_put_contents($path, implode(PHP_EOL, $lines).PHP_EOL, LOCK_EX) === false || ! chmod($path, 0600)) {
    throw new RuntimeException('Unable to create a protected production MySQL client file.');
}
PHP;

        return str_replace('__PATH__', $pathExport, $php);
    }

    private function mysqlOptionValue(string $value): string
    {
        return '"'.str_replace(
            ['\\', "\n", "\r", '"'],
            ['\\\\', '\\n', '\\r', '\\"'],
            $value,
        ).'"';
    }

    private function ensureSuccessful(ProcessResult $result, string $operation): void
    {
        if ($result->successful()) {
            return;
        }

        $error = Str::limit(trim($result->errorOutput()), 500);
        throw new RuntimeException("Failed to {$operation}".($error !== '' ? ": {$error}" : '.'));
    }

    private function timeout(): int
    {
        $timeout = config('kraite.clone.timeout_seconds', 1800);

        return is_int($timeout) && $timeout > 0 ? $timeout : 1800;
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
