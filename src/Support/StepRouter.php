<?php

declare(strict_types=1);

namespace Kraite\Core\Support;

use Illuminate\Database\Eloquent\Collection;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ForbiddenHostname;
use Kraite\Core\Models\Server;
use StepDispatcher\Exceptions\NoCleanWorkerException;
use StepDispatcher\Models\Step;

/**
 * StepRouter
 *
 * Dispatch-time queue resolver for Kraite. Registered via
 * `StepDispatcher::setQueueResolver()` in `CoreServiceProvider::boot()`.
 *
 * Responsibility: when step-dispatcher is about to push a step onto Redis,
 * this class picks the physical per-hostname queue (e.g. `eos-positions`)
 * based on the step's logical category and the current ban set for the
 * step's (account, api_system) pair. Ineligible workers (whose IPs appear
 * in active `forbidden_hostnames` rows) are filtered out of the candidate
 * pool. When every eligible worker is banned AND a permanent ban type
 * exists, the router fires the deactivation cascade (flips
 * `account.is_active=0`, dispatches the `account_all_workers_blacklisted`
 * notification to the account owner) and throws `NoCleanWorkerException`,
 * which step-dispatcher's outer try/catch translates into a Failed step.
 *
 * Pure (no-account) steps — orchestrators like `DispatchAccountBalancesJob`
 * — skip the ban filter and route to any eligible worker for the logical
 * queue. Sync steps never reach the router (step-dispatcher bypasses the
 * hook entirely for `queue=sync`).
 */
final class StepRouter
{
    /**
     * Hook entry point registered with step-dispatcher.
     *
     * Contract per step-dispatcher v1.13.0:
     *   - returns `string` → step-dispatcher overrides `step.queue` and pushes there
     *   - returns `null` → step-dispatcher leaves `step.queue` as-is (no opinion)
     *   - throws `NoCleanWorkerException` → step-dispatcher transitions to Failed
     *
     * Side effects (deactivation, notification) are fired BEFORE the throw.
     */
    public function resolveQueueName(Step $step): ?string
    {
        $logical = $this->extractLogicalQueue($step);
        $candidates = $this->candidatesFor($logical);

        if ($candidates === []) {
            // Unknown logical queue — no subscriptions configured. Defer
            // to step-dispatcher's default behaviour (uses step.queue
            // verbatim) so legacy / external-app queues keep working.
            return null;
        }

        $accountId = $this->extractAccountId($step);

        if ($accountId === null) {
            // Orchestrator / no-account step. Ban filtering is moot —
            // any eligible worker can take it.
            return $this->buildPhysicalQueue($logical, $this->pickRandom($candidates));
        }

        $account = Account::find($accountId);

        if ($account === null) {
            // Account not in DB (test seed gap, stale step from a deleted
            // account, etc). Fall through — no ban context to apply.
            return $this->buildPhysicalQueue($logical, $this->pickRandom($candidates));
        }

        $apiSystem = ApiSystem::find($account->api_system_id);

        if ($apiSystem === null) {
            return $this->buildPhysicalQueue($logical, $this->pickRandom($candidates));
        }

        $activeBans = $this->loadActiveBans($apiSystem->id, $accountId);

        if ($this->hasAccountBlocked($activeBans)) {
            // account_blocked = bad API key. No IP rotation can save it.
            // Deactivate the account immediately so the cron stops fanning
            // to it, fire the loud notification, and fail this step.
            $this->deactivateAccountAndNotify($account, $apiSystem);

            throw new NoCleanWorkerException(sprintf(
                'Account #%d API key is blocked on %s — account deactivated.',
                $accountId,
                $apiSystem->canonical
            ));
        }

        $bannedIps = $this->rotationBannedIps($activeBans);

        $cleanCandidates = $this->filterCleanCandidates($candidates, $bannedIps);

        if ($cleanCandidates !== []) {
            return $this->buildPhysicalQueue($logical, $this->pickRandom($cleanCandidates));
        }

        // No clean candidate. Terminal cascade is reserved for permanent
        // bans (ip_not_whitelisted) — those need user action to clear and
        // won't recover on their own. Temporary bans (ip_rate_limited /
        // ip_banned with a future expiry) just need to wait out the
        // window; return null so step-dispatcher uses step.queue verbatim
        // and the existing retry mechanics take over.
        if ($this->hasPermanentBan($activeBans)) {
            $this->deactivateAccountAndNotify($account, $apiSystem);

            throw new NoCleanWorkerException(sprintf(
                'Account #%d has no clean worker on %s — every eligible IP is blacklisted. Account deactivated.',
                $accountId,
                $apiSystem->canonical
            ));
        }

        return null;
    }

    /**
     * Pull the logical queue name out of `step.queue`, stripping any
     * known-hostname prefix introduced by a previous resolver pass
     * (e.g. `eos-positions` → `positions` on a retry).
     */
    public function extractLogicalQueue(Step $step): string
    {
        $queue = $step->queue ?? 'default';

        foreach ($this->knownHostnames() as $hostname) {
            $prefix = $hostname.'-';

            if (str_starts_with($queue, $prefix)) {
                return mb_substr($queue, mb_strlen($prefix));
            }
        }

        return $queue;
    }

    /**
     * List of hostnames eligible for serving a given logical queue,
     * derived from `config('kraite.horizon.workers')`. The workers config
     * is the single source of truth for fleet topology — both this
     * candidate-set view AND `config/horizon.php`'s supervisor block
     * read from it, eliminating the drift risk of a two-map design.
     *
     * Per-hostname queues (where logical name == hostname) are NOT
     * included in any candidate set — those are targeted dispatches,
     * not subscriptions.
     *
     * Result is memoised per-process so repeated dispatches under high
     * fan-out (e.g. 200-account balance cron) don't re-walk the config
     * array on every call.
     *
     * @return array<int, string>
     */
    public function candidatesFor(string $logical): array
    {
        $map = $this->candidateMap();

        return $map[$logical] ?? [];
    }

    /**
     * Resolve every Redis queue that can receive a logical queue's jobs.
     * Unknown logical queues remain unrouted and therefore keep their raw name.
     *
     * @return array<int, string>
     */
    public function physicalQueuesFor(string $logical): array
    {
        $candidates = $this->candidatesFor($logical);

        if ($candidates === []) {
            return [$logical];
        }

        return array_map(
            fn (string $hostname): string => $this->buildPhysicalQueue($logical, $hostname),
            $candidates,
        );
    }

    /**
     * Build (and memoise) the inverse map of `kraite.horizon.workers`:
     * logical_queue → [hostnames]. Per-hostname queues are skipped
     * (they aren't a routing target, just a worker's direct mailbox).
     *
     * The candidate pool is env-aware:
     *   - `local`       → only the `local` worker key (if present)
     *   - `production`  → all worker keys EXCEPT `local`
     *   - other (e.g. `testing`) → no filter; the seeded config wins
     *
     * Without this filter the dispatcher on a Mac dev box would happily
     * pick a production hostname (e.g. `eos`), push a job onto
     * `eos-positions`, and no local Horizon supervisor would ever
     * consume it — recover-stale then promotes the dispatched step to
     * `priority` and the loop accumulates orphan Redis jobs.
     *
     * The cache is keyed by a content hash of the workers config AND
     * the resolved environment so mutating either mid-test forces a
     * rebuild — important for the integration test suite which seeds
     * different topologies per test.
     *
     * @return array<string, array<int, string>>
     */
    private function candidateMap(): array
    {
        static $cache = [];

        $workers = config('kraite.horizon.workers', []);

        if (! is_array($workers)) {
            return [];
        }

        $environment = $this->resolvedEnvironment();
        $workers = $this->filterWorkersForEnvironment($workers, $environment);

        $key = md5(serialize($workers).'|'.$environment);

        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $map = [];

        foreach ($workers as $hostname => $logicalQueues) {
            if (! is_array($logicalQueues)) {
                continue;
            }

            foreach ($logicalQueues as $logical => $_overrides) {
                // Skip per-hostname targeted queues — those aren't a
                // routing candidate, just a worker's direct mailbox
                // (used during account onboarding connectivity tests).
                if ($logical === $hostname) {
                    continue;
                }

                $map[$logical] ??= [];
                $map[$logical][] = $hostname;
            }
        }

        return $cache[$key] = $map;
    }

    /**
     * The environment label that drives candidate-pool selection. We
     * read HORIZON_ENV first (set per-host on every prod box to its
     * own hostname like `eos`, `athena`) and fall back to APP_ENV so
     * Bruno's local Mac (no HORIZON_ENV) resolves to `local`.
     */
    private function resolvedEnvironment(): string
    {
        return (string) (env('HORIZON_ENV') ?? env('APP_ENV', 'production'));
    }

    /**
     * Strip worker keys that don't belong on the current box.
     *
     * @param  array<string, mixed>  $workers
     * @return array<string, mixed>
     */
    private function filterWorkersForEnvironment(array $workers, string $environment): array
    {
        if ($environment === 'local') {
            return array_intersect_key($workers, ['local' => true]);
        }

        if ($environment === 'testing') {
            return $workers;
        }

        // Production-style envs (HORIZON_ENV = athena | eos | iris | …
        // or APP_ENV=production). Pool excludes the `local` developer
        // worker so the dispatcher never targets a queue no prod box
        // is subscribed to.
        unset($workers['local']);

        return $workers;
    }

    /**
     * Compose the physical queue name. If the logical name already
     * equals the chosen hostname (e.g. `Step::create(['queue' => 'eos'])`
     * targeting the per-hostname queue directly), we don't double-suffix.
     */
    public function buildPhysicalQueue(string $logical, string $hostname): string
    {
        if ($logical === $hostname) {
            return $hostname;
        }

        return "{$hostname}-{$logical}";
    }

    /**
     * @param  array<int, string>  $candidates
     * @param  array<int, string>  $bannedIps
     * @return array<int, string>
     */
    public function filterCleanCandidates(array $candidates, array $bannedIps): array
    {
        if ($candidates === [] || $bannedIps === []) {
            return $candidates;
        }

        $bannedHostnames = Server::query()
            ->whereIn('ip_address', $bannedIps)
            ->pluck('hostname')
            ->all();

        return array_values(array_diff($candidates, $bannedHostnames));
    }

    /**
     * @param  array<int, string>  $candidates
     */
    private function pickRandom(array $candidates): string
    {
        return $candidates[array_rand($candidates)];
    }

    /**
     * List of known hostnames used for suffix stripping in
     * `extractLogicalQueue`. Re-queried per call so test pollution
     * (RefreshDatabase wiping the Server table between tests) can't
     * leave stale hostnames in a process-level cache.
     *
     * The query is a single indexed pluck on a small table (~5 rows in
     * production), so the per-dispatch overhead is negligible even
     * under high-fanout workloads (e.g. 200-account balance cron fan).
     *
     * @return array<int, string>
     */
    private function knownHostnames(): array
    {
        return Server::query()->pluck('hostname')->all();
    }

    /**
     * Pull accountId from step.arguments first (canonical place job
     * constructors look), fall back to step.relatable_id when the
     * step is typed as related to an Account.
     */
    private function extractAccountId(Step $step): ?int
    {
        $args = $step->arguments ?? [];

        if (is_array($args) && isset($args['accountId']) && is_numeric($args['accountId'])) {
            return (int) $args['accountId'];
        }

        if ($step->relatable_type === Account::class && $step->relatable_id !== null) {
            return (int) $step->relatable_id;
        }

        return null;
    }

    /**
     * Active = `forbidden_until` is NULL (no expiry) or > now (window
     * still open). Includes BOTH account-scoped rows and system-wide
     * rows (account_id IS NULL applies to all accounts).
     *
     * @return Collection<int, ForbiddenHostname>
     */
    private function loadActiveBans(int $apiSystemId, int $accountId): Collection
    {
        return ForbiddenHostname::query()
            ->where('api_system_id', $apiSystemId)
            ->where(static function ($query) use ($accountId): void {
                $query->where('account_id', $accountId)->orWhereNull('account_id');
            })
            ->where(static function ($query): void {
                $query->whereNull('forbidden_until')->orWhere('forbidden_until', '>', now());
            })
            ->get();
    }

    /**
     * @param  Collection<int, ForbiddenHostname>  $bans
     */
    private function hasAccountBlocked(Collection $bans): bool
    {
        return $bans->contains(
            static fn (ForbiddenHostname $ban): bool => $ban->type === ForbiddenHostname::TYPE_ACCOUNT_BLOCKED
        );
    }

    /**
     * @param  Collection<int, ForbiddenHostname>  $bans
     */
    private function hasPermanentBan(Collection $bans): bool
    {
        return $bans->contains(
            static fn (ForbiddenHostname $ban): bool => $ban->type === ForbiddenHostname::TYPE_IP_NOT_WHITELISTED
        );
    }

    /**
     * IP-rotation-eligible bans: ip_not_whitelisted, ip_rate_limited,
     * ip_banned. account_blocked is excluded here because it doesn't
     * depend on the IP — it's about the credentials.
     *
     * @param  Collection<int, ForbiddenHostname>  $bans
     * @return array<int, string>
     */
    private function rotationBannedIps(Collection $bans): array
    {
        return $bans
            ->whereIn('type', [
                ForbiddenHostname::TYPE_IP_NOT_WHITELISTED,
                ForbiddenHostname::TYPE_IP_RATE_LIMITED,
                ForbiddenHostname::TYPE_IP_BANNED,
            ])
            ->pluck('ip_address')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Terminal sink. Fires when:
     *   1. account_blocked detected (no IP rotation can save it)
     *   2. Every eligible worker IP is blacklisted AND at least one ban
     *      is the permanent (user-actionable) type
     *
     * Disables the account so downstream cronjobs / dispatchers stop
     * fanning steps to it, and fires the loud "portfolio at risk"
     * notification to the account owner. Throttled via the notification
     * canonical's cache_key (account_id, api_system) so concurrent
     * resolver calls for the same account don't spam the user.
     */
    private function deactivateAccountAndNotify(Account $account, ApiSystem $apiSystem): void
    {
        $reason = sprintf(
            'All worker IPs blacklisted on %s — fix whitelist/API key and reactivate',
            $apiSystem->name
        );

        if ($account->is_active) {
            $account->update([
                'is_active' => false,
                'disabled_reason' => $reason,
                'disabled_at' => now(),
            ]);

            $owner = $account->user;

            if ($owner !== null) {
                NotificationService::send(
                    user: $owner,
                    canonical: 'account_all_workers_blacklisted',
                    referenceData: [
                        'apiSystem' => $apiSystem,
                        'account' => $account,
                        'api_system' => $apiSystem->canonical,
                        'account_id' => $account->id,
                        'account_name' => $account->name,
                    ],
                    relatable: $account,
                    cacheKeys: [
                        'account_id' => $account->id,
                        'api_system' => $apiSystem->canonical,
                    ]
                );
            }
        }
    }
}
