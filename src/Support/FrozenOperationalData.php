<?php

declare(strict_types=1);

namespace Kraite\Core\Support;

use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

final class FrozenOperationalData
{
    /** @var list<string> */
    private const TABLES = [
        'orders',
        'positions',
        'steps',
        'steps_dispatcher_ticks',
        'steps_archive',
        'trading_steps',
        'trading_steps_dispatcher_ticks',
        'trading_steps_archive',
        'api_request_logs',
        'api_snapshots',
        'notification_logs',
        'model_logs',
    ];

    /** @return list<string> */
    public function tables(): array
    {
        return self::TABLES;
    }

    /**
     * @return array{tables: array<string, int>, queues: array<string, int>, logs: int}
     */
    public function state(): array
    {
        $tableCounts = [];

        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $tableCounts[$table] = DB::table($table)->count();
        }

        return [
            'tables' => $tableCounts,
            'queues' => $this->queueDepths(),
            'logs' => $this->logItemCount(),
        ];
    }

    /** @param array{tables: array<string, int>, queues: array<string, int>, logs: int}|null $state */
    public function isClean(?array $state = null): bool
    {
        $state ??= $this->state();

        return array_sum($state['tables']) === 0
            && array_sum($state['queues']) === 0
            && $state['logs'] === 0;
    }

    /**
     * @param  array{tables: array<string, int>, queues: array<string, int>, logs: int}  $state
     * @return list<string>
     */
    public function dirtySummary(array $state): array
    {
        $summary = [];

        foreach ($state['tables'] as $table => $count) {
            if ($count > 0) {
                $summary[] = "{$table}: {$count}";
            }
        }

        foreach ($state['queues'] as $queue => $count) {
            if ($count > 0) {
                $summary[] = "queue {$queue}: {$count}";
            }
        }

        if ($state['logs'] > 0) {
            $summary[] = "log items: {$state['logs']}";
        }

        return $summary;
    }

    public function clean(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');

        try {
            foreach (self::TABLES as $table) {
                if (! Schema::hasTable($table)) {
                    continue;
                }

                if (app()->environment('testing')) {
                    DB::table($table)->delete();

                    continue;
                }

                DB::table($table)->truncate();
            }
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        }

        $queue = Queue::connection();
        if ($queue instanceof RedisQueue) {
            foreach ($this->queueNames() as $queueName) {
                $queue->clear($queueName);
            }
        }

        $this->cleanLogs();
    }

    /** @return array<string, int> */
    private function queueDepths(): array
    {
        $queue = Queue::connection();

        if (! $queue instanceof RedisQueue) {
            return [];
        }

        $depths = [];
        foreach ($this->queueNames() as $queueName) {
            $depths[$queueName] = $queue->size($queueName);
        }

        return $depths;
    }

    /** @return list<string> */
    private function queueNames(): array
    {
        $names = ['default'];
        $workers = config('kraite.horizon.workers', []);

        if (is_array($workers)) {
            foreach ($workers as $hostname => $logicalQueues) {
                if (! is_string($hostname) || ! is_array($logicalQueues)) {
                    continue;
                }

                foreach (array_keys($logicalQueues) as $logicalQueue) {
                    if (! is_string($logicalQueue)) {
                        continue;
                    }

                    $names[] = $logicalQueue === $hostname
                        ? $hostname
                        : "{$hostname}-{$logicalQueue}";
                }
            }
        }

        $configuredDefault = config('queue.connections.redis.queue');
        if (is_string($configuredDefault) && $configuredDefault !== '') {
            $names[] = $configuredDefault;
        }

        $fleetQueue = config('kraite.fleet_metrics.queue');
        if (is_string($fleetQueue) && $fleetQueue !== '') {
            $names[] = $fleetQueue;
        }

        return array_values(array_unique($names));
    }

    private function logsPath(): string
    {
        $configuredPath = config('kraite.freeze.logs_path');

        return is_string($configuredPath) && $configuredPath !== ''
            ? $configuredPath
            : storage_path(app()->environment('testing') ? 'framework/testing/kraite-freeze-logs' : 'logs');
    }

    private function logItemCount(): int
    {
        $path = $this->logsPath();

        if (! File::isDirectory($path)) {
            return 0;
        }

        return count(File::directories($path)) + count(File::glob($path.'/*.log'));
    }

    private function cleanLogs(): void
    {
        $path = $this->logsPath();

        if (! File::isDirectory($path)) {
            return;
        }

        foreach (File::directories($path) as $directory) {
            if (! is_string($directory)) {
                continue;
            }

            File::deleteDirectory($directory);
        }

        foreach (File::glob($path.'/*.log') as $logFile) {
            if (! is_string($logFile)) {
                continue;
            }

            File::delete($logFile);
        }
    }
}
