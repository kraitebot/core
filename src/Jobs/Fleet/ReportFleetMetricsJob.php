<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Fleet;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Kraite\Core\Support\Fleet\FleetMetricsCollector;
use Kraite\Core\Support\Fleet\FleetMetricsRepository;
use Kraite\Core\Support\FreezeMode;
use Throwable;

/**
 * One-shot fleet-metrics writer.
 *
 * The ingestion Laravel schedule owns the five-minute cadence. Warmup may
 * dispatch one immediate copy so a freshly deployed host reports before the
 * next scheduler tick.
 */
final class ReportFleetMetricsJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(public string $hostname) {}

    public static function resolveHostname(): string
    {
        $configured = config('kraite.fleet_metrics.hostname');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $hostname = gethostname();

        return is_string($hostname) && $hostname !== '' ? $hostname : 'unknown';
    }

    /**
     * Queue one immediate heartbeat for a host. The uniqueness lock makes
     * repeated warmup calls idempotent while the job is pending.
     */
    public static function seed(string $hostname): void
    {
        if (FreezeMode::isActive()) {
            return;
        }

        self::routed($hostname);
    }

    public function uniqueId(): string
    {
        return 'fleet-metrics:'.$this->hostname;
    }

    public function uniqueFor(): int
    {
        return (int) config('kraite.fleet_metrics.report_interval_seconds', 300) + 600;
    }

    public function handle(FleetMetricsCollector $collector, FleetMetricsRepository $repository): void
    {
        if (FreezeMode::isActive()) {
            return;
        }

        try {
            $payload = $collector->collect($this->hostname, config('kraite.server_role'));
            $repository->write($this->hostname, $payload);
        } catch (Throwable $exception) {
            Log::channel('jobs')->error('[FLEET-METRICS] collect/write failed', [
                'hostname' => $this->hostname,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private static function routed(string $hostname): PendingDispatch
    {
        $pending = self::dispatch($hostname);

        $connection = config('kraite.fleet_metrics.connection');
        if (is_string($connection) && $connection !== '') {
            $pending->onConnection($connection);
        }

        $queue = config('kraite.fleet_metrics.queue');

        return $pending->onQueue(is_string($queue) && $queue !== '' ? $queue : $hostname);
    }
}
