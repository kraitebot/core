# Fleet metrics heartbeat

The single Kraite production host writes a live-vitals snapshot to Redis every
five minutes. The admin dashboard and system-health watchdog read that
snapshot to render the host and alert when reporting stops.

## Runtime

The ingestion application's routes/console.php is the cadence source:

    php artisan kraite:fleet-report

The command collects CPU, memory, disk, uptime, version, and Supervisor unit
states, then writes the key for the current logical hostname. Warmup queues one
immediate snapshot so a deployment does not wait for the next scheduler tick.

No standalone Bash agent, systemd timer, or per-project cron entry is required.
Production needs only the ingestion Laravel scheduler process.

## Storage

Snapshots use the dedicated unprefixed fleet Redis connection and the key
kraite:fleet:<hostname>. The key TTL removes abandoned state; liveness uses
the reported_at timestamp and configured stale threshold.

## Configuration

The fleet_metrics section in config/kraite.php owns the key prefix, Redis
database, report interval, TTL, stale threshold, hostname override, connection,
and queue.

## Adding or replacing a host

Update the server roster and deployment topology, deploy the application, and
run warmup. The scheduled command and watchdog discover the roster change
without any host-specific monitoring script.
