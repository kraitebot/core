# Fleet metrics — heartbeat agents

Each box writes a live-vitals snapshot to the Redis key `kraite:fleet:<hostname>`
every ~5 minutes. The admin dashboard (`console.kraite.com/dashboard`) and the
system-health watchdog read those keys, joined against the `kraite.fleet.servers`
registry, to render the fleet and alert on silence.

The keys live on a dedicated, **unprefixed** Redis connection (`fleet`, injected
by `CoreServiceProvider::boot`, database 2) so every app agrees on the literal
key regardless of its own `REDIS_PREFIX`.

## The 9 PHP boxes (athena, eos, iris, nyx, hemera, palaemon, aristaeus, tyche, pheme)

Nothing to install — the heartbeat is a self-rescheduling Laravel job
(`ReportFleetMetricsJob`) seeded automatically by `kraite:warmup` on every
deploy. Each box's Horizon consumes its own `<hostname>` queue (1 proc), runs
the job, writes the key, and re-queues the next tick with a 5-minute delay.

Manual controls (run as the box's hostname user):

```bash
php artisan kraite:fleet-report          # write one snapshot now (sync)
php artisan kraite:fleet-report --seed    # (re)start the self-rescheduling loop
```

`--seed` is idempotent — the job is unique per host, so re-seeding can never
spawn a second loop.

## hyperion (database + redis — no Laravel app, no Horizon)

hyperion can't run the PHP job, so it uses the standalone bash + systemd agent
in this directory, which writes the same key shape via raw `redis-cli`.

Install (as root on hyperion):

```bash
install -m 0755 hyperion-fleet-report.sh /usr/local/bin/hyperion-fleet-report.sh
install -m 0644 kraite-fleet-metrics.service /etc/systemd/system/kraite-fleet-metrics.service
install -m 0644 kraite-fleet-metrics.timer   /etc/systemd/system/kraite-fleet-metrics.timer
systemctl daemon-reload
systemctl enable --now kraite-fleet-metrics.timer

# verify
/usr/local/bin/hyperion-fleet-report.sh && \
  redis-cli -a "$(grep -h '^requirepass' /etc/redis/conf.d/*.conf | head -1 | awk '{print $2}')" \
    --no-auth-warning -n 2 GET kraite:fleet:hyperion
systemctl list-timers kraite-fleet-metrics.timer
```

## Configuration (config/kraite.php → `fleet_metrics`)

| key | default | meaning |
|---|---|---|
| `key_prefix` | `kraite:fleet:` | Redis key namespace (must match the bash agent) |
| `redis_database` | `2` | the kraite Redis DB the `fleet` connection pins to |
| `report_interval_seconds` | `300` | write cadence / job re-dispatch delay |
| `ttl_seconds` | `604800` | key TTL — decommission GC horizon, NOT liveness |
| `stale_after_seconds` | `720` | `reported_at` age past which a box reads DOWN |

`stale_after` (12 min) sits well above a clean reboot (~1–2 min) so a box
bouncing through a deploy never pages; only a box that fails to come back trips
the `fleet_box_silent_<host>` watchdog alert.

## Adding a new box

1. Add the host to `config/kraite.php` → `fleet.servers` (and `horizon.workers`
   if it runs Horizon) and reseed the `servers` table — the deploy drift gate
   already requires this.
2. Deploy. `kraite:warmup` seeds the heartbeat loop automatically.

The dashboard + watchdog pick it up with **zero code changes** — both iterate
the registry and overlay live keys. A box that's registered but not yet
reporting shows `missing` until its first heartbeat lands.
