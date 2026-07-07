<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Fleet;

use Carbon\CarbonImmutable;
use Composer\InstalledVersions;
use Throwable;

/**
 * Gathers THIS box's live vitals for the fleet-metrics heartbeat.
 *
 * Everything here is read locally on the box the job runs on — host vitals
 * (`/proc`, `df`) and supervisor process state (`supervisorctl`) are the
 * things ONLY the box can see; queue depth / dispatcher throughput are
 * deliberately NOT collected here because the dashboard reads those centrally
 * from the shared Redis / MySQL.
 *
 * Every probe is defensive: a missing `/proc` (macOS dev box), an absent
 * `supervisorctl`, or a permission error yields null/empty for that field
 * rather than throwing — a partial snapshot is more useful than none. The
 * parse helpers are pure + static so the format handling is unit-testable
 * without a Linux host.
 */
final class FleetMetricsCollector
{
    /**
     * Build the full snapshot payload for the given host.
     *
     * @return array<string, mixed>
     */
    public function collect(string $hostname, ?string $role = null): array
    {
        return [
            'hostname' => $hostname,
            'role' => $role,
            'reported_at' => CarbonImmutable::now()->toIso8601String(),
            'version' => $this->coreVersion(),
            'uptime_seconds' => $this->uptimeSeconds(),
            'boot_id' => $this->bootId(),
            'cpu' => $this->cpu(),
            'ram' => $this->ram(),
            'disk' => $this->disk(),
            'units' => $this->services(),
        ];
    }

    /**
     * Deployed kraitebot/core version — the fleet's common denominator across
     * every app checkout (workers run ingestion, pheme runs the web apps, but
     * all of them carry core). The admin deploy panel groups hosts by this to
     * surface rollout drift. Null when composer metadata is unreadable
     * (hyperion's bash agent never reports one).
     */
    private function coreVersion(): ?string
    {
        try {
            return InstalledVersions::getPrettyVersion('kraitebot/core');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Seconds since the box last booted. Linux reads `/proc/uptime`; macOS (dev
     * box) derives it from `sysctl kern.boottime`. A sudden drop (38d → 40s) is
     * how the reader detects a reboot. Null when neither source is readable.
     */
    private function uptimeSeconds(): ?int
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            return $this->macUptimeSeconds();
        }

        $raw = @file_get_contents('/proc/uptime');

        return $raw === false ? null : self::parseUptime($raw);
    }

    /**
     * Kernel boot UUID — regenerated on every boot, so a changed value vs the
     * last snapshot is a definitive reboot signal (more robust than uptime,
     * which can wobble around clock skew). Null off Linux.
     */
    private function bootId(): ?string
    {
        $raw = @file_get_contents('/proc/sys/kernel/random/boot_id');

        if ($raw === false) {
            return null;
        }

        $trimmed = trim($raw);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return array{load1: float|null, cores: int|null, percent: float|null}
     */
    private function cpu(): array
    {
        $load1 = null;
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            $load1 = $load === false ? null : round((float) $load[0], 2);
        }

        $cores = $this->cpuCores();

        return [
            'load1' => $load1,
            'cores' => $cores,
            'percent' => self::cpuPercent($load1, $cores),
        ];
    }

    /**
     * Logical CPU count — drives the load → percent conversion. Counts
     * `processor` lines in `/proc/cpuinfo`; null off Linux (no hardcoded
     * fallback — a wrong core count silently skews every percent).
     */
    private function cpuCores(): ?int
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            return $this->sysctlInt('hw.logicalcpu');
        }

        $raw = @file_get_contents('/proc/cpuinfo');

        if ($raw === false) {
            return null;
        }

        $count = preg_match_all('/^processor\s*:/m', $raw);

        return $count > 0 ? $count : null;
    }

    /**
     * @return array{used_mb: int|null, total_mb: int|null, percent: float|null}
     */
    private function ram(): array
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            return $this->macRam();
        }

        $raw = @file_get_contents('/proc/meminfo');

        if ($raw === false) {
            return ['used_mb' => null, 'total_mb' => null, 'percent' => null];
        }

        return self::parseMeminfo($raw);
    }

    /**
     * @return array{used_gb: float|null, total_gb: float|null, percent: float|null}
     */
    private function disk(): array
    {
        $free = @disk_free_space('/');
        $total = @disk_total_space('/');

        if ($free === false || $total === false || $total <= 0) {
            return ['used_gb' => null, 'total_gb' => null, 'percent' => null];
        }

        $used = $total - $free;

        return [
            'used_gb' => round($used / (1024 ** 3), 1),
            'total_gb' => round($total / (1024 ** 3), 1),
            'percent' => round(($used / $total) * 100, 1),
        ];
    }

    /**
     * Process-manager state per platform: the "is horizon / scheduler /
     * dispatch-daemon / WS streamer actually up?" signal the central DB can't
     * see. macOS (dev) reads Herd's LaunchAgents via `launchctl`; Linux (prod)
     * reads `supervisorctl`. Either way: a map of unit name → RUNNING/STOPPED.
     *
     * @return array<string, string>
     */
    private function services(): array
    {
        return PHP_OS_FAMILY === 'Darwin'
            ? $this->macServices()
            : $this->linuxServices();
    }

    /**
     * Linux services across the two process managers the fleet uses: supervisor
     * on the trading boxes (athena + workers) and systemd on the web box
     * (pheme, `kraite-horizon-*` units). Supervisor is tried first (it owns the
     * trading stack); a box with no supervisor programs falls through to the
     * systemd units. Merged so a hybrid box surfaces both.
     *
     * @return array<string, string>
     */
    private function linuxServices(): array
    {
        $units = $this->supervisorUnits();

        return $units !== [] ? $units : $this->systemdUnits();
    }

    /**
     * Kraite systemd units (`kraite-*.service`) → ActiveState, mapped to the
     * RUNNING/STOPPED vocabulary the dashboard expects. Read-only `systemctl`
     * needs no privilege. Empty when systemd or the units are absent.
     *
     * @return array<string, string>
     */
    private function systemdUnits(): array
    {
        if (! function_exists('shell_exec')) {
            return [];
        }

        try {
            $output = @shell_exec("systemctl list-units 'kraite-*.service' --type=service --all --no-pager --plain --no-legend 2>/dev/null");
        } catch (Throwable) {
            return [];
        }

        return is_string($output) ? self::parseSystemdUnits($output) : [];
    }

    /**
     * macOS: this site's Herd-managed daemons (horizon, scheduler, and any
     * registered workers/streamers) from `launchctl list`, filtered to the
     * current app so the dashboard reports THIS box's services rather than
     * every site on the machine.
     *
     * @return array<string, string>
     */
    private function macServices(): array
    {
        if (! function_exists('shell_exec')) {
            return [];
        }

        $site = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (! is_string($site) || $site === '') {
            return [];
        }

        try {
            $output = @shell_exec('launchctl list 2>/dev/null');
        } catch (Throwable) {
            return [];
        }

        return is_string($output) ? self::parseLaunchctlList($output, $site) : [];
    }

    /**
     * macOS RAM: total from `sysctl hw.memsize`, used from `vm_stat`
     * (active + wired + compressed pages). Nulls when either probe is absent.
     *
     * @return array{used_mb: int|null, total_mb: int|null, percent: float|null}
     */
    private function macRam(): array
    {
        $total = $this->sysctlInt('hw.memsize');

        if ($total === null || $total <= 0 || ! function_exists('shell_exec')) {
            return ['used_mb' => null, 'total_mb' => null, 'percent' => null];
        }

        $vmstat = @shell_exec('vm_stat 2>/dev/null');

        return is_string($vmstat)
            ? self::parseVmStat($vmstat, $total)
            : ['used_mb' => null, 'total_mb' => null, 'percent' => null];
    }

    /**
     * macOS uptime: now − `sysctl kern.boottime`'s `sec` field. Null when the
     * probe is unreadable.
     */
    private function macUptimeSeconds(): ?int
    {
        if (! function_exists('shell_exec')) {
            return null;
        }

        $raw = @shell_exec('sysctl -n kern.boottime 2>/dev/null');

        if (! is_string($raw) || preg_match('/sec\s*=\s*(\d+)/', $raw, $m) !== 1) {
            return null;
        }

        return max(0, time() - (int) $m[1]);
    }

    /**
     * Read a single integer `sysctl` value (e.g. `hw.logicalcpu`,
     * `hw.memsize`). Null off macOS / when `shell_exec` is unavailable.
     */
    private function sysctlInt(string $key): ?int
    {
        if (! function_exists('shell_exec')) {
            return null;
        }

        $out = @shell_exec('sysctl -n '.escapeshellarg($key).' 2>/dev/null');

        if (! is_string($out)) {
            return null;
        }

        $out = trim($out);

        return is_numeric($out) ? (int) $out : null;
    }

    /**
     * Map of supervisor program → state (RUNNING / STOPPED / FATAL …) for the
     * kraite-managed units on this box. This is the "is horizon /
     * dispatch-daemon / WS streamer actually up?" signal the central DB
     * can't see. Run through `sudo -n` because the box-user can't read the
     * supervisor socket directly (every fleet box has passwordless sudo per the
     * 2026-05-23 hardening principles); empty when supervisor is absent.
     *
     * @return array<string, string>
     */
    private function supervisorUnits(): array
    {
        try {
            if (! function_exists('shell_exec')) {
                return [];
            }

            $output = @shell_exec('sudo -n supervisorctl status 2>/dev/null');
        } catch (Throwable) {
            return [];
        }

        if (! is_string($output) || trim($output) === '') {
            return [];
        }

        return self::parseSupervisorStatus($output);
    }

    /**
     * `/proc/uptime` is "<seconds-since-boot> <idle-seconds>". Take the first
     * float, floor to whole seconds. Returns null on an unparseable line.
     */
    public static function parseUptime(string $raw): ?int
    {
        $first = strtok(trim($raw), ' ');

        if ($first === false || ! is_numeric($first)) {
            return null;
        }

        return (int) floor((float) $first);
    }

    /**
     * Convert 1-minute load average to a 0–100 percent of total CPU capacity.
     * Null when either input is missing, clamped to 100.
     */
    public static function cpuPercent(?float $load1, ?int $cores): ?float
    {
        if ($load1 === null || $cores === null || $cores <= 0) {
            return null;
        }

        return round(min(($load1 / $cores) * 100, 100), 1);
    }

    /**
     * Parse `/proc/meminfo` into used / total MiB + used percent. Used =
     * MemTotal − MemAvailable (the kernel's own "actually free for new work"
     * figure, which already accounts for reclaimable cache). Returns nulls if
     * either key is absent.
     *
     * @return array{used_mb: int|null, total_mb: int|null, percent: float|null}
     */
    public static function parseMeminfo(string $raw): array
    {
        $totalKb = self::meminfoValueKb($raw, 'MemTotal');
        $availKb = self::meminfoValueKb($raw, 'MemAvailable');

        if ($totalKb === null || $availKb === null || $totalKb <= 0) {
            return ['used_mb' => null, 'total_mb' => null, 'percent' => null];
        }

        $usedKb = max($totalKb - $availKb, 0);

        return [
            'used_mb' => (int) round($usedKb / 1024),
            'total_mb' => (int) round($totalKb / 1024),
            'percent' => round(($usedKb / $totalKb) * 100, 1),
        ];
    }

    private static function meminfoValueKb(string $raw, string $key): ?int
    {
        if (preg_match('/^'.preg_quote($key, '/').':\s+(\d+)\s*kB/m', $raw, $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * Parse `supervisorctl status` lines into program → state. Each line is
     * "<name>  <STATE>  <description...>"; we keep only the leading token and
     * the uppercase state word. Single-process programs and `group:member`
     * entries both work (the name is taken verbatim up to the first run of
     * whitespace).
     *
     * @return array<string, string>
     */
    public static function parseSupervisorStatus(string $output): array
    {
        $units = [];

        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if (preg_match('/^(\S+)\s+([A-Z]+)\b/', $line, $m) === 1) {
                $units[$m[1]] = $m[2];
            }
        }

        return $units;
    }

    /**
     * Parse `launchctl list` into unit → state for one site. Each line is
     * "<PID>\t<LastExitStatus>\t<Label>"; we keep only labels prefixed with
     * "<site>." (e.g. `admin.kraite.test.horizon`), strip that prefix for the
     * display name, and treat a numeric PID as RUNNING (a `-` PID means the
     * daemon is loaded but not currently running).
     *
     * @return array<string, string>
     */
    public static function parseLaunchctlList(string $output, string $site): array
    {
        $units = [];
        $prefix = $site.'.';

        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            $cols = preg_split('/\t+/', trim($line)) ?: [];

            if (count($cols) < 3) {
                continue;
            }

            [$pid, , $label] = $cols;

            if (! str_starts_with($label, $prefix)) {
                continue;
            }

            $name = substr($label, strlen($prefix));
            $units[$name] = ($pid !== '-' && $pid !== '') ? 'RUNNING' : 'STOPPED';
        }

        return $units;
    }

    /**
     * Parse `vm_stat` into used / total MiB + used percent for a known total.
     * "Used" = (active + wired + compressed) pages × page size — the macOS
     * analogue of Linux's MemTotal − MemAvailable. Page size is read from the
     * header ("page size of N bytes"), defaulting to 4096.
     *
     * @return array{used_mb: int|null, total_mb: int|null, percent: float|null}
     */
    public static function parseVmStat(string $raw, int $totalBytes): array
    {
        if ($totalBytes <= 0) {
            return ['used_mb' => null, 'total_mb' => null, 'percent' => null];
        }

        $page = preg_match('/page size of (\d+) bytes/', $raw, $m) === 1 ? (int) $m[1] : 4096;

        $pages = static function (string $key) use ($raw): int {
            return preg_match('/'.preg_quote($key, '/').':\s+(\d+)\./', $raw, $m) === 1 ? (int) $m[1] : 0;
        };

        $usedBytes = ($pages('Pages active') + $pages('Pages wired down') + $pages('Pages occupied by compressor')) * $page;

        return [
            'used_mb' => (int) round($usedBytes / 1048576),
            'total_mb' => (int) round($totalBytes / 1048576),
            'percent' => round(min(($usedBytes / $totalBytes) * 100, 100), 1),
        ];
    }

    /**
     * Parse `systemctl list-units 'kraite-*.service' --plain --no-legend` into
     * unit → state. Columns are "UNIT LOAD ACTIVE SUB DESCRIPTION"; we keep the
     * unit (minus the `.service` suffix) and map ActiveState `active` → RUNNING,
     * everything else (inactive / failed / activating) to its uppercased state.
     * A leading status glyph on failed units is tolerated.
     *
     * @return array<string, string>
     */
    public static function parseSystemdUnits(string $output): array
    {
        $units = [];

        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            $cols = preg_split('/\s+/', trim($line)) ?: [];

            if ($cols !== [] && ! str_ends_with((string) $cols[0], '.service')) {
                array_shift($cols); // drop a leading "●" / "*" status glyph
            }

            if (count($cols) < 3 || ! str_ends_with((string) $cols[0], '.service')) {
                continue;
            }

            $name = substr((string) $cols[0], 0, -strlen('.service'));
            $units[$name] = $cols[2] === 'active' ? 'RUNNING' : mb_strtoupper((string) $cols[2]);
        }

        return $units;
    }
}
