<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Fleet;

use Carbon\CarbonImmutable;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;
use Kraite\Core\Jobs\Fleet\ReportFleetMetricsJob;
use Kraite\Core\Models\Server;
use Throwable;

/**
 * Read/write side of the fleet-metrics heartbeat.
 *
 * Writer: each box's {@see ReportFleetMetricsJob}
 * (and hyperion's bash agent, via raw redis-cli) writes one key per host.
 * Reader: the admin dashboard and the system-health watchdog both call
 * {@see all()} to render / alert on the fleet.
 *
 * The expected roster is the `servers` table — NOT a Redis key scan, and no
 * longer the static `kraite.fleet.servers` config. The table is the runtime
 * source of truth every core consumer already reads (StepRouter, the
 * verify-fleet-topology drift gate, AccountServerConnectivityService); the
 * config is merely what the seeder writes into it, per-environment. We GET
 * each known host's key directly, so:
 *   - a provisioned-but-never-reporting box still shows up (status `missing`),
 *     which is the entire reason the roster exists; and
 *   - we never touch `KEYS` (disabled on hyperion) — a fixed set of GETs.
 *
 * Liveness is the `reported_at` age, never key existence: the key carries a
 * long TTL purely as a decommission garbage-collector, while `stale_after`
 * decides online vs DOWN.
 */
final class FleetMetricsRepository
{
    /**
     * Persist this box's snapshot. Always stamps a fresh `reported_at` so the
     * reader's age math is anchored on write time even if the collector's
     * clock and the value drift. TTL is the GC horizon, not the liveness gate.
     *
     * @param  array<string, mixed>  $payload
     */
    public function write(string $hostname, array $payload): void
    {
        $payload['hostname'] = $hostname;
        $payload['reported_at'] = CarbonImmutable::now()->toIso8601String();

        $this->connection()->setex(
            $this->key($hostname),
            $this->ttlSeconds(),
            json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Raw decoded snapshot for one host, or null when no key exists / the
     * payload is unreadable.
     *
     * @return array<string, mixed>|null
     */
    public function read(string $hostname): ?array
    {
        try {
            $raw = $this->connection()->get($this->key($hostname));
        } catch (Throwable) {
            return null;
        }

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * The whole fleet, roster-joined and classified. One row per `servers`
     * table host: present-and-fresh rows carry their live metrics; silent rows
     * are flagged `stale` (key present, reported_at aged) or `missing` (no key
     * at all — never reported / decommissioned). The roster is environment-
     * scoped by the seeder, so a local box yields just itself while production
     * yields the full fleet.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        $staleAfter = $this->staleAfterSeconds();
        $now = CarbonImmutable::now();

        $rows = [];

        foreach (Server::query()->orderBy('id')->get() as $server) {
            $snapshot = $this->read($server->hostname);
            $rows[] = $this->classify(
                $server->hostname,
                [
                    'ip_address' => $server->ip_address,
                    'type' => $server->type,
                    'description' => $server->description,
                    'registered_at' => $server->created_at?->toIso8601String(),
                ],
                $snapshot,
                $now,
                $staleAfter,
            );
        }

        return $rows;
    }

    /**
     * Subset of {@see all()} that is NOT online — the rows the watchdog
     * alerts on (missing or stale).
     *
     * @return array<int, array<string, mixed>>
     */
    public function silentHosts(): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (array $row): bool => $row['status'] !== 'online',
        ));
    }

    /**
     * Shape one host into the dashboard/watchdog row: registry metadata +
     * derived liveness (`online` / `stale` / `missing`), age, recent-reboot
     * flag, and the live metric block (null when silent).
     *
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>|null  $snapshot
     * @return array<string, mixed>
     */
    private function classify(
        string $hostname,
        array $meta,
        ?array $snapshot,
        CarbonImmutable $now,
        int $staleAfter,
    ): array {
        $base = [
            'hostname' => $hostname,
            'ip_address' => $meta['ip_address'] ?? null,
            'type' => $meta['type'] ?? null,
            'description' => $meta['description'] ?? null,
            // When the host joined the roster (servers.created_at) — the
            // watchdog's provisioning-grace anchor for `missing` rows.
            'registered_at' => $meta['registered_at'] ?? null,
        ];

        if ($snapshot === null) {
            return $base + [
                'status' => 'missing',
                'reported_at' => null,
                'age_seconds' => null,
                'recently_rebooted' => false,
                'uptime_seconds' => null,
                'boot_id' => null,
                'version' => null,
                'cpu' => null,
                'ram' => null,
                'disk' => null,
                'units' => [],
            ];
        }

        $age = $this->ageSeconds($snapshot['reported_at'] ?? null, $now);
        $status = ($age === null || $age > $staleAfter) ? 'stale' : 'online';
        $uptime = isset($snapshot['uptime_seconds']) ? (int) $snapshot['uptime_seconds'] : null;

        return $base + [
            'status' => $status,
            'reported_at' => $snapshot['reported_at'] ?? null,
            'age_seconds' => $age,
            // A fresh boot (uptime below two report intervals) the box has
            // since recovered from — surfaced so the UI can badge "rebooted".
            'recently_rebooted' => $uptime !== null && $uptime < ($this->reportIntervalSeconds() * 2),
            'uptime_seconds' => $uptime,
            'boot_id' => $snapshot['boot_id'] ?? null,
            'version' => $snapshot['version'] ?? null,
            'cpu' => $snapshot['cpu'] ?? null,
            'ram' => $snapshot['ram'] ?? null,
            'disk' => $snapshot['disk'] ?? null,
            'units' => $snapshot['units'] ?? [],
        ];
    }

    private function ageSeconds(mixed $reportedAt, CarbonImmutable $now): ?int
    {
        if (! is_string($reportedAt) || $reportedAt === '') {
            return null;
        }

        try {
            $at = CarbonImmutable::parse($reportedAt);
        } catch (Throwable) {
            return null;
        }

        // A future stamp (writer clock ahead of reader) is treated as "just
        // reported" rather than a negative age.
        return max(0, (int) $at->diffInSeconds($now, false));
    }

    private function connection(): Connection
    {
        return Redis::connection('fleet');
    }

    private function key(string $hostname): string
    {
        return ((string) config('kraite.fleet_metrics.key_prefix', 'kraite:fleet:')).$hostname;
    }

    private function ttlSeconds(): int
    {
        return (int) config('kraite.fleet_metrics.ttl_seconds', 604800);
    }

    private function staleAfterSeconds(): int
    {
        return (int) config('kraite.fleet_metrics.stale_after_seconds', 720);
    }

    private function reportIntervalSeconds(): int
    {
        return (int) config('kraite.fleet_metrics.report_interval_seconds', 300);
    }
}
