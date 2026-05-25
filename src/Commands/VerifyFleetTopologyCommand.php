<?php

declare(strict_types=1);

namespace Kraite\Core\Commands;

use Illuminate\Console\Command;
use Kraite\Core\Models\Server;

/**
 * Asserts deploy-time alignment between `config('kraite.horizon.workers')`
 * (per-hostname supervisor topology — drives Horizon + StepRouter
 * candidates) and the `servers` table (canonical source of hostname →
 * public IP, used by StepRouter to translate forbidden_hostnames IPs
 * back to hostnames during ban filtering).
 *
 * The invariant: every key in `kraite.horizon.workers` MUST have a
 * matching `servers.hostname` row. Without it, StepRouter cannot map
 * a banned IP back to the hostname that hosts it, and the ban filter
 * silently lets the would-be-banned worker into the candidate set —
 * the cascade never fires, the loud notification never lands, and the
 * step ends up landing on a worker that immediately re-fails the API
 * call. The whole rotation engine becomes a no-op.
 *
 * Drift can arise two ways:
 *  - config/kraite.php edited (new worker added) without inserting the
 *    matching servers row — the more common path during fleet expansion.
 *  - servers row inserted (or hostname renamed) without updating
 *    config/kraite.php — rarer but possible during DR/migration.
 *
 * This command catches both. Invoked by `deploy.sh` after config:cache
 * and before `horizon:terminate`, so a broken deploy fails BEFORE the
 * supervisors respawn against the stale state. Also runnable manually
 * (without --fail-on-drift) for inspection.
 *
 * Exit codes:
 *   0 — topologies aligned (or --fail-on-drift not passed and drift detected)
 *   1 — drift detected AND --fail-on-drift was passed
 */
final class VerifyFleetTopologyCommand extends Command
{
    protected $signature = 'kraite:verify-fleet-topology
                            {--fail-on-drift : Exit non-zero (code 1) when drift is detected. Use in deploy scripts.}
                            {--quiet-on-success : Suppress the OK line when topologies align.}';

    protected $description = 'Assert kraite.horizon.workers config keys match servers.hostname rows (catches deploy-time drift).';

    public function handle(): int
    {
        $configKeys = array_keys(config('kraite.horizon.workers', []));
        $apiableHostnames = Server::query()->where('is_apiable', true)->pluck('hostname')->all();
        $allHostnames = Server::query()->pluck('hostname')->all();

        $issues = [];

        // Direction 1 (the load-bearing one): every config key must have
        // a matching servers.hostname row. Without it, the StepRouter's
        // Server::whereIn('ip_address', $bannedIps)->pluck('hostname')
        // can't translate a banned IP into the hostname that belongs to
        // a config key, so the filter never finds it as a candidate to
        // remove.
        $missingServers = array_diff($configKeys, $allHostnames);

        foreach ($missingServers as $hostname) {
            $issues[] = "config/kraite.php declares worker '{$hostname}' but no servers.hostname row exists for it — the StepRouter cannot map this worker's banned IPs back to the config key, so ban filtering silently fails for it.";
        }

        // Direction 2 (operational hygiene): every apiable server should
        // appear in the config. An orphan apiable server is a worker that
        // has been provisioned but isn't receiving any work — almost
        // always a config-update-forgot-to-deploy situation. Non-apiable
        // servers (hyperion = DB+Redis only) are excluded — they're
        // expected to NOT appear in horizon.workers.
        $orphanApiableServers = array_diff($apiableHostnames, $configKeys);

        foreach ($orphanApiableServers as $hostname) {
            $issues[] = "Server row '{$hostname}' is is_apiable=true but config/kraite.php horizon.workers has no block for it — this worker is provisioned but will never receive any dispatched steps.";
        }

        if ($issues !== []) {
            $this->error('Fleet topology drift detected:');

            foreach ($issues as $issue) {
                $this->error('  - '.$issue);
            }

            return (bool) $this->option('fail-on-drift') ? self::FAILURE : self::SUCCESS;
        }

        if (! (bool) $this->option('quiet-on-success')) {
            $this->info(sprintf(
                'Fleet topology aligned: %d worker(s) in config, %d apiable server(s) in DB.',
                count($configKeys),
                count($apiableHostnames),
            ));
        }

        return self::SUCCESS;
    }
}
