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
use Throwable;

/**
 * Self-rescheduling fleet-metrics heartbeat.
 *
 * This is the box's own pulse: collect local vitals → write the Redis key →
 * re-dispatch a delayed copy of itself onto THIS box's own `<hostname>` queue
 * (logical == hostname → the literal `<hostname>` physical queue, consumed by
 * exactly one Horizon process on this box). No scheduler is involved — workers
 * run none — and there is no central fan-out, so the loop is per-box isolated:
 * if a box's Horizon dies, only its own pulse stops and the reader flags it
 * DOWN; the rest of the fleet keeps reporting.
 *
 * Plain queued job (not a step-dispatcher BaseQueueableJob) on purpose: a step
 * would write a `steps` row every five minutes per box, forever. The heartbeat
 * must leave no trail beyond the single Redis key it overwrites.
 *
 * Robustness:
 *  - `ShouldBeUniqueUntilProcessing` keyed by hostname makes seeding
 *    idempotent: re-seeding at warmup can't spawn a second loop while one is
 *    pending. The lock releases as processing starts, so the in-handle
 *    re-dispatch is free to enqueue the next tick.
 *  - The re-dispatch lives in `finally`, so a thrown collector/write keeps the
 *    chain alive — one failed tick can't silently kill the heartbeat.
 *  - `tries = 1`: the loop itself is the retry; we don't want framework
 *    retries stacking duplicate pulses.
 */
final class ReportFleetMetricsJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(public string $hostname) {}

    /**
     * This box's logical roster hostname. Defaults to the OS hostname (true on
     * production, where the box is literally named after its roster entry); a
     * box whose OS hostname differs from its roster name (a dev machine →
     * `local`) overrides it via `kraite.fleet_metrics.hostname`.
     */
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
     * Kick off (or revive) the heartbeat loop for a host, routed per config:
     * the default connection + `<hostname>` queue on production; an overridden
     * connection/queue on a box whose Horizon consumes a shared queue. Unique
     * lock keeps it idempotent.
     */
    public static function seed(string $hostname): void
    {
        self::routed($hostname);
    }

    /**
     * Build a routed PendingDispatch (connection + queue from config). Returned
     * so callers can chain `->delay()` for the self-rescheduling tick.
     */
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

    /**
     * One live loop per host. The lock window (uniqueFor) sits above the
     * report interval so a pending delayed tick holds the slot until it runs.
     */
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
        try {
            $payload = $collector->collect($this->hostname, config('kraite.server_role'));
            $repository->write($this->hostname, $payload);
        } catch (Throwable $exception) {
            Log::channel('jobs')->error('[FLEET-METRICS] collect/write failed', [
                'hostname' => $this->hostname,
                'error' => $exception->getMessage(),
            ]);
        } finally {
            $this->scheduleNext();
        }
    }

    /**
     * Queue the next pulse onto this box's own `<hostname>` queue, delayed by
     * the report interval. Wrapped defensively: even a dispatch failure must
     * not turn into an unhandled job failure (which `tries=1` would drop) —
     * the warmup seed is the backstop that revives a dead loop.
     */
    private function scheduleNext(): void
    {
        // The sync driver runs dispatched jobs inline and ignores delay(), so
        // re-dispatching here would recurse forever. The heartbeat only makes
        // sense on a real queue (redis/Horizon) anyway — on sync we write one
        // snapshot and stop.
        if (config('queue.default') === 'sync') {
            return;
        }

        try {
            self::routed($this->hostname)
                ->delay(now()->addSeconds((int) config('kraite.fleet_metrics.report_interval_seconds', 300)));
        } catch (Throwable $exception) {
            Log::channel('jobs')->error('[FLEET-METRICS] failed to reschedule heartbeat', [
                'hostname' => $this->hostname,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
