<?php

declare(strict_types=1);

namespace Kraite\Core\Commands\Cronjobs;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Kraite\Core\Jobs\Atomic\Order\SyncPositionOrdersJob as AtomicSyncPositionOrdersJob;
use Kraite\Core\Jobs\Lifecycles\Order\PrepareSyncOrdersJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\BinanceListenKey;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\Position;
use StepDispatcher\Models\Step;
use Kraite\Core\Support\Health\OrphanReconciler;
use Kraite\Core\Support\MaintenanceMode;
use Kraite\Core\Support\Math;
use Kraite\Core\Support\NotificationService;
use Kraite\Core\Support\ValueObjects\ApiProperties;
use StepDispatcher\Support\BaseCommand;
use Throwable;

/**
 * CheckSystemHealthCommand
 *
 * Runs a dozen-plus staleness / connectivity checks every 7 minutes
 * (`routes/console.php` → cron expression every 7 minutes). Paired with the
 * every-5-min drift watchdog as the faster alert-side companion —
 * Health follows up with the auto-cancel pass on its next tick. The
 * 7-min cadence was chosen at v1.20.1 (2026-05-04) to absorb the
 * per-account orphan-reconciliation cost (exchange snapshot fetch +
 * classification) without dragging the cheaper checks below their
 * useful frequency. Each check emits zero-or-many alerts via the
 * shared `system_health_alert` notification canonical, with a
 * per-signal cache key (5-minute throttle) so distinct failures dedupe
 * independently while sharing the same Pushover / email plumbing.
 *
 * Why one command for all of them: the notification canonical is
 * shared, the throttle behaviour is shared, the loop / try-catch /
 * log shape is shared. A single sequential pass keeps one cron
 * entry, one log line per run, and adding a new check is a single
 * new private method. No notifications-table churn per check.
 *
 * Severity is informational only — Pushover delivery doesn't filter
 * on it. The field is here so the dashboard / model_logs can render
 * differently if Bruno ever wants tiered display.
 */
final class CheckSystemHealthCommand extends BaseCommand
{
    private const MARK_PRICE_STALENESS_SECONDS = 60;

    /**
     * Grace window before a never-synced sidecar row (mark_price_synced_at
     * IS NULL) is treated as a missing-price alert. Covers the normal
     * onboarding gap: ExchangeSymbolObserver creates the sidecar at
     * symbol creation, then the price daemon's pair-map refresh runs
     * every 5 min — newly-onboarded symbols can legitimately sit at null
     * for up to that window. Past it, "still null" means the daemon's
     * pair mapper missed the symbol (pair-name mismatch) or the daemon
     * was offline through the onboarding window. Both cases are real
     * failures: token discovery currently treats null timestamps as
     * "pass" (HasTokenDiscovery line 293-295), so the symbol gets
     * selected for slots and position-sizing reads a null mark_price
     * downstream.
     */
    private const MARK_PRICE_MISSING_GRACE_MINUTES = 5;

    /**
     * Two-pronged failed_jobs alerting. The lifetime row count
     * (`FAILED_JOBS_CAPACITY_THRESHOLD`) is a backstop — "stop
     * monitoring, start cleaning"; the rolling-1h count
     * (`FAILED_JOBS_RECENT_THRESHOLD`) is the operationally useful
     * "something is currently failing right now" signal. Splitting
     * them stops yesterday's investigated-but-not-pruned incident
     * from masquerading as a fresh failure, AND surfaces dominant
     * exception class + dominant job class in the recent-burst
     * Pushover so the operator navigates to root cause without
     * a second SQL query.
     */
    private const FAILED_JOBS_RECENT_THRESHOLD = 5;

    private const FAILED_JOBS_CAPACITY_THRESHOLD = 1000;

    private const FAILED_JOBS_RECENT_WINDOW_MINUTES = 60;

    /**
     * Per-queue Horizon-depth thresholds. Each queue carries a
     * different operational profile and a different urgency, so a
     * single sum threshold masks dangerous safety-critical backlogs:
     * a `positions` queue at 100 pending ops means 100 delayed
     * exposure-protection actions across accounts, but with the old
     * sum-only check the alert stayed silent until 5000+ total. Per-
     * queue thresholds page on each safety-critical queue
     * independently of the long-running indicator burst.
     *
     * Tuning rationale:
     *  - positions / orders: tight (50). These carry SL/TP placement
     *    and sync ops; backlog = exposure delay.
     *  - user-data-stream: 100. Frame routing should be near-zero;
     *    sustained backlog = WS handler stall.
     *  - priority: 200. TP/SL escalation; small but matters.
     *  - default / cronjobs: 500 / 500. Utility scope.
     *  - indicators: 5000. Hourly conclude burst dispatches ~600 entry
     *    steps that promote into ~thousands of indicator jobs; depth
     *    in low thousands during the burst is normal and should not
     *    page.
     *
     * @var array<string, int>
     */
    private const HORIZON_QUEUE_DEPTH_THRESHOLDS = [
        'positions' => 50,
        'orders' => 50,
        'user-data-stream' => 100,
        'priority' => 200,
        'default' => 500,
        'cronjobs' => 500,
        'indicators' => 5000,
    ];

    /**
     * Indicators run hourly via `kraite:cron-conclude-symbols-direction`
     * at :30. The chain spawns ~600 entry steps and runs serially per
     * symbol with TAAPI throttle backpressure, so a healthy pass takes
     * 50–65 min start-to-last-symbol. 120 min absorbs the slow-pass
     * days (post supervisor restarts, TAAPI rate-limit pressure,
     * larger-than-usual symbol set) where the LAST symbol in the
     * batch crosses 90 min before the next :30 cron tick refreshes
     * it. Two consecutive missed cycles (120 min = >2× cadence)
     * still trips on a genuinely broken cron.
     */
    private const INDICATOR_STALENESS_MINUTES = 120;

    /**
     * Balance cron runs every 5 minutes. The threshold sits at 15 min
     * to absorb a single missed cycle without firing — supervisor
     * restarts (per-prefix cooldown rollout 2026-05-08, OPTIMIZE TABLE
     * pauses, scheduler reboots) routinely eat one 5-min slot, and a
     * 10-min threshold tripped on every such gap. 15 min still catches
     * a genuinely broken cron (two consecutive misses = real outage)
     * while ignoring single-cycle transients.
     */
    private const BALANCE_STALENESS_MINUTES = 15;

    private const DAEMON_HEARTBEAT_STALENESS_SECONDS = 120;

    private const SCHEDULER_LIVENESS_SECONDS = 120;

    /**
     * Disk-pressure threshold. Below this percent of free space on
     * the root filesystem, emit a high-severity alert. Logs grow
     * faster than DB rows on this server (see horizon.log size),
     * so disk pressure shows up before any other capacity signal.
     */
    private const DISK_FREE_PERCENT_MIN = 15;

    /**
     * A position flipped to `syncing` by SyncPositionOrdersJob and not flipped
     * back to `active` after this many minutes — with no live sync step still
     * running for that position — is wedged. The reactive cron skips it
     * (PrepareSyncOrdersJob early-returns when the position isn't `active`),
     * the drift spotter skips it (also active-only), and the retry chain has
     * already exhausted. Operator action is required to unstick it.
     */
    private const STALE_SYNCING_POSITION_MINUTES = 15;

    protected $signature = 'kraite:cron-check-system-health
                            {--output : Display command output (silent by default)}';

    protected $description = 'Run ten staleness / connectivity checks across the bot\'s critical data paths and alert per failed signal.';

    /**
     * Checks that depend on the steps-dispatcher actively draining
     * Pending steps. When `MaintenanceMode::pauseStepsDispatch()` is
     * armed (intentional ops window), these signals trivially go
     * stale because the upstream cron path writes via Step::create →
     * dispatcher promotion → worker execution. Pausing the
     * dispatcher freezes that pipeline by design, so the operator
     * does NOT want a Pushover storm five minutes into a planned
     * pause. Daemon-driven signals (mark price, daemon heartbeat,
     * scheduler liveness) keep firing — they don't depend on the
     * dispatcher and a real outage on those paths is still
     * actionable mid-pause.
     */
    private const DISPATCHER_DEPENDENT_CHECKS = [
        'checkIndicatorFreshness',
        'checkAccountBalanceFreshness',
    ];

    public function handle(): int
    {
        $checks = [
            'checkMarkPriceFreshness',
            'checkIndicatorFreshness',
            'checkAccountBalanceFreshness',
            'checkDaemonHeartbeat',
            // Dispatcher-progress liveness is owned outside this
            // command: `kraite-dispatch-daemon` supervisor process
            // (alive via `supervisorctl status`) plus the step-
            // dispatcher package's own `steps:recover-stale
            // --recover-dispatched --release-locks` cron (wedge
            // detection per prefix). A direct in-command tick check
            // was tried (2026-05-03) using
            // `steps_dispatcher.last_selected_at` and removed because
            // that column only updates on root-step CREATE — quiet
            // periods between minute-level crons fired false positives
            // while the dispatcher was healthy. Worker-side starvation
            // is caught here by `checkHorizonQueueDepth` +
            // `checkFailedJobsOverflow`.
            'checkSchedulerLiveness',
            'checkFailedJobsOverflow',
            'checkDatabaseConnection',
            'checkRedisConnection',
            'checkHorizonQueueDepth',
            'checkOrphanReconciliation',
            'checkDiskPressure',
            'checkStaleSyncingPositions',
            'checkUserDataDeadLetters',
        ];

        // The two dispatcher-dependent checks (indicator + balance
        // freshness) read tables populated by DEFAULT-prefix crons —
        // klines, balance snapshots, indicator conclusion. They go
        // stale when the default dispatcher is paused, regardless of
        // whether the trading dispatcher is also paused. So gate on
        // the default prefix specifically; an all-scope pause also
        // returns true here because `isStepsDispatchPaused` ORs the
        // all-scope flag onto every per-prefix check.
        $dispatchPaused = MaintenanceMode::isStepsDispatchPaused('');

        $alertCount = 0;

        foreach ($checks as $check) {
            if ($dispatchPaused && in_array($check, self::DISPATCHER_DEPENDENT_CHECKS, true)) {
                continue;
            }

            try {
                $alertCount += $this->{$check}();
            } catch (Throwable $exception) {
                // A check that itself crashes is worth knowing about —
                // surface as an alert with a distinct signal so we can
                // tell "the check broke" from "the check found a stale
                // thing." Doesn't abort the rest of the run.
                Log::channel('jobs')->error('[SYSTEM-HEALTH] check threw', [
                    'check' => $check,
                    'error' => $exception->getMessage(),
                ]);

                $this->emit(
                    signal: 'check_threw_'.$check,
                    severity: 'medium',
                    title: 'System health check raised an exception',
                    detail: "{$check}: {$exception->getMessage()}",
                );

                $alertCount++;
            }
        }

        $this->verboseInfo("System health pass complete. Alerts emitted: {$alertCount}");

        return self::SUCCESS;
    }

    /**
     * #0 — `exchange_symbol_prices.mark_price_synced_at` per symbol
     * that's tradeable OR carries an open position. The price daemon
     * writes this column on every WS frame; staleness past 60s =
     * the daemon is no longer publishing fresh prices for a symbol
     * the bot has skin in. Same predicate as the indicator check
     * to keep the alert volume actionable across both.
     *
     * Cutover note (2026-05-04): the freshness column moved from
     * `exchange_symbols` to the dedicated `exchange_symbol_prices`
     * sidecar. The query joins on `exchange_symbol_id` so
     * eligibility predicates (tradeable / open-position) still
     * apply on the parent table while the freshness filter
     * targets the narrow table.
     */
    private function checkMarkPriceFreshness(): int
    {
        $threshold = now()->subSeconds(self::MARK_PRICE_STALENESS_SECONDS);
        $missingGraceCutoff = now()->subMinutes(self::MARK_PRICE_MISSING_GRACE_MINUTES);

        $alerts = 0;

        // Branch 1 — STALE: sidecar has a timestamp but it's older than
        // the freshness threshold. Daemon was running, then stopped or
        // got pair-mapper drift mid-flight.
        $stale = $this->eligibleExchangeSymbolsQuery()
            ->notDelisted()
            ->join('exchange_symbol_prices', 'exchange_symbol_prices.exchange_symbol_id', '=', 'exchange_symbols.id')
            ->whereNotNull('exchange_symbol_prices.mark_price_synced_at')
            ->where('exchange_symbol_prices.mark_price_synced_at', '<', $threshold)
            ->orderBy('exchange_symbol_prices.mark_price_synced_at')
            ->get([
                'exchange_symbols.id',
                'exchange_symbols.token',
                'exchange_symbols.quote',
                'exchange_symbol_prices.mark_price_synced_at',
            ]);

        foreach ($stale as $row) {
            $pair = $row->token.$row->quote;
            $age = (int) now()->diffInSeconds($row->mark_price_synced_at, true);
            $this->emit(
                signal: "mark_price_stale_{$pair}",
                severity: 'high',
                title: "Mark price stale for {$pair}",
                detail: "exchange_symbol_prices.mark_price_synced_at is {$age}s old (threshold: ".self::MARK_PRICE_STALENESS_SECONDS."s) for exchange_symbol #{$row->id}. Price daemon is no longer publishing fresh prices for a symbol the bot has skin in (open position or tradeable).",
            );
            $alerts++;
        }

        // Branch 2 — MISSING: sidecar timestamp is null past the
        // onboarding grace window. Token discovery currently treats
        // null timestamps as "pass" (HasTokenDiscovery line 293-295),
        // so without this alert a never-synced symbol can get selected
        // for slots and position-sizing math reads a null mark_price.
        // Distinct signal name lets ops triage "never synced" (likely
        // pair-mapping issue) vs "stale" (likely daemon issue).
        $missing = $this->eligibleExchangeSymbolsQuery()
            ->notDelisted()
            ->join('exchange_symbol_prices', 'exchange_symbol_prices.exchange_symbol_id', '=', 'exchange_symbols.id')
            ->whereNull('exchange_symbol_prices.mark_price_synced_at')
            ->where('exchange_symbol_prices.created_at', '<', $missingGraceCutoff)
            ->orderBy('exchange_symbol_prices.created_at')
            ->get([
                'exchange_symbols.id',
                'exchange_symbols.token',
                'exchange_symbols.quote',
                'exchange_symbol_prices.created_at',
            ]);

        foreach ($missing as $row) {
            $pair = $row->token.$row->quote;
            $sidecarAgeMinutes = (int) now()->diffInMinutes($row->created_at, true);
            $this->emit(
                signal: "mark_price_missing_{$pair}",
                severity: 'high',
                title: "Mark price never synced for {$pair}",
                detail: "exchange_symbol_prices.mark_price_synced_at IS NULL for exchange_symbol #{$row->id} ({$pair}); sidecar row created {$sidecarAgeMinutes}min ago (grace: ".self::MARK_PRICE_MISSING_GRACE_MINUTES."min). The price daemon never wrote for this symbol — most likely pair-name mismatch in the daemon's pairToIds map, or daemon was offline through the onboarding window. Token discovery passes null-timestamp symbols through the freshness gate, so this symbol can be selected for slots while having no live price.",
            );
            $alerts++;
        }

        return $alerts;
    }

    /**
     * #1 — Indicator freshness per TRADEABLE symbol. Reads
     * `exchange_symbols.indicators_synced_at` directly rather than
     * the `indicator_histories` table.
     *
     * Why tradeable-only (not OR-with-active-position): indicators
     * drive the OPEN-decision direction logic; they do NOT drive
     * management of already-open positions (those run on order
     * state — WAP / SL / TP). Active-position symbols whose
     * indicator data went stale AFTER the position opened (e.g.
     * `has_invalid_indicator_direction` flagged the symbol) are
     * still being managed correctly — the alert isn't actionable.
     *
     * Why the column, not the history table: indicators are
     * computed NATIVELY only on Binance; other exchanges (Bitget /
     * Bybit / Kucoin) receive `direction` via the copy phase of
     * ConcludeSymbolsDirectionCommand, which writes
     * `indicators_synced_at` to the destination row but never
     * inserts an `indicator_histories` row. Querying the history
     * table therefore flags every non-Binance symbol as stale by
     * design. The model's own freshness column is exchange-
     * agnostic and tracks the canonical "this symbol's direction
     * is current" signal that the bot itself uses.
     */
    private function checkIndicatorFreshness(): int
    {
        $threshold = now()->subMinutes(self::INDICATOR_STALENESS_MINUTES);

        $stale = ExchangeSymbol::query()
            ->tradeable()
            ->where(function ($q) use ($threshold): void {
                // Either the column is null (never synced — bot's
                // copy phase failed to populate this tradeable row)
                // or it's older than the threshold.
                $q->whereNull('exchange_symbols.indicators_synced_at')
                    ->orWhere('exchange_symbols.indicators_synced_at', '<', $threshold);
            })
            ->get(['id', 'token', 'quote', 'indicators_synced_at', 'indicators_timeframe']);

        $alerts = 0;
        foreach ($stale as $row) {
            $pair = $row->token.$row->quote;
            $syncedAt = $row->indicators_synced_at?->toDateTimeString() ?? 'NULL (never synced)';
            $timeframe = $row->indicators_timeframe ?? 'unset';
            $this->emit(
                signal: "indicator_stale_{$pair}",
                severity: 'high',
                title: "Indicators stale for {$pair}",
                detail: "exchange_symbols.indicators_synced_at = {$syncedAt} for exchange_symbol #{$row->id} (timeframe: {$timeframe}); threshold ".self::INDICATOR_STALENESS_MINUTES.'min. Source of truth is the column, NOT `indicator_histories` — direction is computed natively on Binance and copied via ConcludeSymbolsDirectionCommand to other exchanges, which writes the column without inserting a history row. Direction-decision logic is reading frozen signals.',
            );
            $alerts++;
        }

        return $alerts;
    }

    /**
     * #2 — `account_balance_history` per `is_active=true` account.
     * Powers PnL accounting + position sizing. Cron writes per-account
     * every minute; >10 minutes silent = the cron is broken or the
     * account's balance fetch is throwing.
     */
    private function checkAccountBalanceFreshness(): int
    {
        $threshold = now()->subMinutes(self::BALANCE_STALENESS_MINUTES);

        $accounts = Account::query()
            ->where('is_active', true)
            ->get(['id', 'name']);

        $alerts = 0;
        foreach ($accounts as $account) {
            // Per-account try/catch — a transient DB error on the
            // staleness probe for one account must not abort the
            // remaining accounts' freshness checks. Watchdog runs
            // every 7 minutes; per-row isolation keeps the rest of
            // the fleet observable even when one account's row
            // throws.
            try {
                $latest = DB::table('account_balance_history')
                    ->where('account_id', $account->id)
                    ->max('created_at');

                if ($latest !== null && $latest >= $threshold) {
                    continue;
                }

                $this->emit(
                    signal: "balance_stale_account_{$account->id}",
                    severity: 'high',
                    title: "Account balance history stale (#{$account->id})",
                    detail: 'No `account_balance_history` row newer than '.self::BALANCE_STALENESS_MINUTES."min for account #{$account->id} ({$account->name}). Projections and wallet-history surfaces may read stale numbers.",
                );
                $alerts++;
            } catch (Throwable $e) {
                Log::channel('jobs')->error('[SYSTEM-HEALTH] balance freshness probe threw — continuing', [
                    'account_id' => $account->id,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $alerts;
    }

    /**
     * #3 — User-data daemon heartbeat flag file. Touched every 30s by
     * the daemon's health tick. A stale mtime means the event loop is
     * wedged even though `supervisorctl status` reports RUNNING.
     */
    private function checkDaemonHeartbeat(): int
    {
        $path = storage_path('app/user-data-daemon.heartbeat');

        if (! is_file($path)) {
            // No heartbeat file at all — daemon never wrote one (could
            // be the supervisor program is stopped, the daemon never
            // got past startup, or the storage path is misconfigured).
            $this->emit(
                signal: 'daemon_heartbeat_missing',
                severity: 'high',
                title: 'User-data daemon heartbeat file missing',
                detail: "{$path} does not exist. Daemon has never run, or the path / permissions are misconfigured.",
            );

            return 1;
        }

        $age = time() - filemtime($path);
        if ($age <= self::DAEMON_HEARTBEAT_STALENESS_SECONDS) {
            return 0;
        }

        $this->emit(
            signal: 'daemon_heartbeat_stale',
            severity: 'high',
            title: 'User-data daemon heartbeat stale',
            detail: "Daemon heartbeat flag has not been touched in {$age}s (threshold: ".self::DAEMON_HEARTBEAT_STALENESS_SECONDS.'s). Event loop is likely wedged even though supervisorctl reports RUNNING.',
        );

        return 1;
    }

    /**
     * #5 — Scheduler liveness. We use the keepalive cron's footprint
     * (`binance_listen_keys.last_keep_alive_at`) as a proxy: that cron
     * runs every minute, so if the newest row's keepalive timestamp
     * is older than 2 minutes, `schedule:work` is dead and the entire
     * cron chain is silent.
     *
     * Limitation: there is NO fallback when zero listen-key rows
     * exist (e.g. fresh deploy pre-account-bootstrap, or every account
     * inactive/deleted). The check is silent in that window —
     * acceptable because production deploys always have active
     * accounts. Listen-key rows themselves being stale-but-present is
     * owned by the separate `kraite:cron-check-binance-listen-keys-stale`
     * cron, not here. If a zero-rows window ever becomes a real
     * production concern, the right fix is a cache-backed
     * `scheduler:last_run_at` heartbeat written from
     * `routes/console.php` via `Schedule::call(...)`, used as primary
     * signal with the listen-key proxy as fallback.
     */
    private function checkSchedulerLiveness(): int
    {
        $keepaliveProxy = BinanceListenKey::query()->max('last_keep_alive_at');

        if ($keepaliveProxy === null) {
            // No proxy available — return silent. See method docblock
            // for the limitation rationale and the future-fix shape.
            return 0;
        }

        $age = (int) now()->diffInSeconds($keepaliveProxy, true);
        if ($age <= self::SCHEDULER_LIVENESS_SECONDS) {
            return 0;
        }

        $this->emit(
            signal: 'scheduler_dead',
            severity: 'high',
            title: 'Scheduler liveness signal stale',
            detail: "No keepalive cron run in the last {$age}s (threshold: ".self::SCHEDULER_LIVENESS_SECONDS.'s). `schedule:work` is likely dead — the entire cron chain is silent.',
        );

        return 1;
    }

    /**
     * #6 — `failed_jobs` health, split into two distinct signals:
     *
     *  - `failed_jobs_recent_burst` — rolling-1h count above
     *    FAILED_JOBS_RECENT_THRESHOLD. Includes top exception class +
     *    top job class so the Pushover names the dominant root cause.
     *
     *  - `failed_jobs_capacity` — total lifetime count above
     *    FAILED_JOBS_CAPACITY_THRESHOLD. Different operator action:
     *    prune the table, not investigate a fresh failure.
     *
     * Each signal has its own cache key so they dedupe independently.
     */
    private function checkFailedJobsOverflow(): int
    {
        $alerts = 0;

        $recentSince = now()->subMinutes(self::FAILED_JOBS_RECENT_WINDOW_MINUTES);
        $recentCount = DB::table('failed_jobs')
            ->where('failed_at', '>=', $recentSince)
            ->count();

        if ($recentCount > self::FAILED_JOBS_RECENT_THRESHOLD) {
            $recent = DB::table('failed_jobs')
                ->where('failed_at', '>=', $recentSince)
                ->get(['exception', 'payload', 'failed_at']);

            $exceptionCounts = [];
            $jobCounts = [];
            $newest = null;
            foreach ($recent as $row) {
                $excClass = $this->extractExceptionClass((string) $row->exception);
                $jobClass = $this->extractJobClass((string) $row->payload);

                $exceptionCounts[$excClass] = ($exceptionCounts[$excClass] ?? 0) + 1;
                $jobCounts[$jobClass] = ($jobCounts[$jobClass] ?? 0) + 1;

                if ($newest === null || $row->failed_at > $newest) {
                    $newest = $row->failed_at;
                }
            }

            arsort($exceptionCounts);
            arsort($jobCounts);

            $topException = array_key_first($exceptionCounts) ?? 'unknown';
            $topExceptionCount = $exceptionCounts[$topException] ?? 0;
            $topJob = array_key_first($jobCounts) ?? 'unknown';
            $topJobCount = $jobCounts[$topJob] ?? 0;

            $this->emit(
                signal: 'failed_jobs_recent_burst',
                severity: 'high',
                title: "failed_jobs recent burst ({$recentCount} in last ".self::FAILED_JOBS_RECENT_WINDOW_MINUTES.'min)',
                detail: sprintf(
                    '%d failures in last %dmin (threshold: %d). Top exception: %s (%d/%d). Top job: %s (%d/%d). Newest: %s. Investigate the dominant exception/job pair before pruning.',
                    $recentCount,
                    self::FAILED_JOBS_RECENT_WINDOW_MINUTES,
                    self::FAILED_JOBS_RECENT_THRESHOLD,
                    $topException,
                    $topExceptionCount,
                    $recentCount,
                    $topJob,
                    $topJobCount,
                    $recentCount,
                    $newest ?? 'unknown',
                ),
            );
            $alerts++;
        }

        $totalCount = DB::table('failed_jobs')->count();
        if ($totalCount > self::FAILED_JOBS_CAPACITY_THRESHOLD) {
            $this->emit(
                signal: 'failed_jobs_capacity',
                severity: 'medium',
                title: "failed_jobs capacity high ({$totalCount} rows total)",
                detail: '`failed_jobs` table has '.$totalCount.' rows (capacity threshold: '.self::FAILED_JOBS_CAPACITY_THRESHOLD.'). Not necessarily a fresh failure — historical accumulation. Operator action: prune investigated rows via `php artisan queue:flush` or targeted DELETE, then revisit. Recent-burst signal (separate alert) covers active failures.',
            );
            $alerts++;
        }

        return $alerts;
    }

    /**
     * Extract the exception class FQN from the `exception` text column.
     * The column stores `ClassName: message\n#0 stack-trace...`.
     * Returns "unknown" on any unexpected shape.
     */
    private function extractExceptionClass(string $exception): string
    {
        $firstLine = strtok($exception, "\n");
        if ($firstLine === false) {
            return 'unknown';
        }

        $colonPos = strpos($firstLine, ':');
        if ($colonPos === false) {
            return trim($firstLine) ?: 'unknown';
        }

        return trim(substr($firstLine, 0, $colonPos)) ?: 'unknown';
    }

    /**
     * Extract the job class FQN from the JSON `payload`. Standard
     * Laravel queue payload exposes `displayName`. Returns "unknown"
     * if the payload is malformed.
     */
    private function extractJobClass(string $payload): string
    {
        $decoded = json_decode($payload, true);
        if (! is_array($decoded)) {
            return 'unknown';
        }

        return (string) ($decoded['displayName'] ?? 'unknown');
    }

    /**
     * #7 — DB connection. If we got this far we already have a
     * connection (we read from it), so the check is just a defensive
     * SELECT-1 against the default connection. The case where this
     * fails is typically a fail-over mid-run.
     */
    private function checkDatabaseConnection(): int
    {
        try {
            DB::select('SELECT 1');

            return 0;
        } catch (Throwable $exception) {
            // We can't write to notification_logs through the same
            // connection; log to file as the last-resort surface.
            Log::channel('jobs')->error('[SYSTEM-HEALTH] DB unreachable', [
                'error' => $exception->getMessage(),
            ]);

            return 1;
        }
    }

    /**
     * #8 — Redis connection. Horizon's whole architecture rides on
     * it; if we lose Redis, every queue is silent and supervised
     * Horizon flips FATAL. The check is a PING against the default
     * connection.
     */
    private function checkRedisConnection(): int
    {
        try {
            Redis::connection()->ping();

            return 0;
        } catch (Throwable $exception) {
            $this->emit(
                signal: 'redis_down',
                severity: 'critical',
                title: 'Redis unreachable',
                detail: 'Default Redis connection ping failed: '.$exception->getMessage().'. Horizon will flip FATAL; every queue is silent.',
            );

            return 1;
        }
    }

    /**
     * #9 — Horizon queue depth, per-queue with profile-tuned
     * thresholds. A growing pending count on a safety-critical queue
     * (positions / orders / user-data-stream) means delayed exposure-
     * protection or stale account state; the old sum-only check
     * masked these behind the indicators burst at 5000. Each
     * breaching queue fires a distinct signal so the operator's
     * Pushover names which queue + threshold + actual depth, and the
     * full per-queue map gets attached for triage context.
     */
    private function checkHorizonQueueDepth(): int
    {
        try {
            $depths = [];
            foreach (array_keys(self::HORIZON_QUEUE_DEPTH_THRESHOLDS) as $queue) {
                $depths[$queue] = (int) Redis::connection()->llen("queues:{$queue}");
            }
        } catch (Throwable) {
            // Redis already covered by check #8; don't double-alert.
            return 0;
        }

        $alerts = 0;
        foreach ($depths as $queue => $depth) {
            $threshold = self::HORIZON_QUEUE_DEPTH_THRESHOLDS[$queue];
            if ($depth <= $threshold) {
                continue;
            }

            $allDepths = collect($depths)
                ->map(fn (int $d, string $q): string => "{$q}:{$d}")
                ->implode(', ');

            $this->emit(
                signal: "horizon_queue_depth_{$queue}",
                severity: 'medium',
                title: "Horizon queue depth high on `{$queue}`",
                detail: "`{$queue}` queue depth = {$depth} (threshold: {$threshold}). Workers are not keeping up — investigate stuck jobs or insufficient processes for this queue. Full per-queue snapshot: {$allDepths}.",
            );
            $alerts++;
        }

        return $alerts;
    }

    /**
     * #12 — Disk pressure on the root filesystem.
     *
     * Logs grow faster than DB rows on this server (see horizon.log
     * size — 43 MB with .1..10 rotated copies). Disk pressure shows
     * up before any other capacity signal: failed_jobs writes start
     * failing when /var is full, supervisor stdout writes block, and
     * the price daemon's reactphp loop wedges on log writes that
     * can't flush.
     *
     * Threshold: 15 % free. Below that, alert with current free /
     * total / used breakdown so the operator can `du -sh /var/log`
     * and friends straight from the alert body.
     *
     * Free / total reads use PHP's `disk_free_space()` /
     * `disk_total_space()` against `/`. Both can return false on
     * permission failures; we treat that as "can't tell, don't
     * fire" — separate `check_threw_*` envelope handles the
     * exception path through the `handle()` runner if PHP itself
     * throws.
     */
    private function checkDiskPressure(): int
    {
        $free = @disk_free_space('/');
        $total = @disk_total_space('/');

        $payload = self::evaluateDiskPressure($free, $total, self::DISK_FREE_PERCENT_MIN);

        if ($payload === null) {
            return 0;
        }

        $this->emit(
            signal: 'disk_pressure_low',
            severity: 'high',
            title: 'Disk pressure on root filesystem',
            detail: "Root filesystem free = {$payload['free_gib']} GiB / {$payload['total_gib']} GiB ({$payload['free_percent_str']}% free, threshold: ".self::DISK_FREE_PERCENT_MIN.'%). Logs and supervisor stdout writes will start failing before any other capacity signal. Investigate `du -sh /var/log /home/waygou/ingestion.kraite.com/storage/logs` and rotate / prune.',
        );

        return 1;
    }

    /**
     * Pure-function disk-pressure evaluation. Extracted so the alert
     * path is unit-testable without a host with low disk. Returns null
     * for all "no alert" cases: healthy, unreadable (false from
     * disk_free/total_space), corrupt total (≤ 0). Returns the
     * structured fields (`free_gib`, `total_gib`, `free_percent_str`)
     * for the emit() detail line otherwise.
     *
     * Boundary semantics: `free_percent >= threshold` is HEALTHY (no
     * alert). Strictly below the threshold fires.
     *
     * @return array{free_gib: string, total_gib: string, free_percent_str: string}|null
     */
    public static function evaluateDiskPressure(
        int|float|false $free,
        int|float|false $total,
        int $thresholdPercent,
    ): ?array {
        if ($free === false || $total === false || $total <= 0) {
            return null;
        }

        $freePercent = ((float) $free / (float) $total) * 100;

        if ($freePercent >= $thresholdPercent) {
            return null;
        }

        return [
            'free_gib' => number_format($free / (1024 ** 3), 2),
            'total_gib' => number_format($total / (1024 ** 3), 2),
            'free_percent_str' => number_format($freePercent, 1),
        ];
    }

    /**
     * #11 — Orphan reconciliation per Binance + Bitget account.
     *
     * Pulls the exchange's open-orders / algo-orders / positions
     * snapshot per active account, compares against Kraite's local
     * `opened()` Position rows + non-terminal Order rows + the rolling
     * window of recently-closed Kraite positions, and uses
     * `OrphanReconciler` to classify what should be cancelled / closed.
     *
     * Per-account behaviour follows the `allow_other_*` flags:
     *   - both `false` (Kraite-exclusive): every orphan flagged
     *   - `allow_other_orders=true`: only Kraite-leftover orphan orders flagged
     *   - `allow_other_positions=true`: orphan positions ignored entirely
     *
     * Match-window for "Kraite-leftover orders" is configurable via
     * `kraite.health_watchdog.orphan_kraite_match_window_minutes`
     * (default 60).
     *
     * Cleanup IS executed inline (not detection-only): orphan orders
     * are auto-cancelled via the per-exchange API and orphan positions
     * are auto-closed via reduce-only MARKET. Each (account, symbol)
     * group emits one Pushover with the cancel/close outcome. Per-
     * account try/catch isolation, the `hasInflightPositions` guard
     * (suppresses order-orphan detection during transitional position
     * states), and the recently-closed match window contain the blast
     * radius. Why this lives in the system-health command instead of a
     * dedicated `kraite:cron-reconcile-orphans`: v1.20.1 (2026-05-04)
     * relaxed the Health cadence to every 7 minutes specifically to
     * absorb this pass's per-account API cost — combined cron, shared
     * `system_health_alert` canonical with per-signal cache key, single
     * log entry per run. Splitting would multiply notification +
     * scheduling surface area for no operator gain.
     */
    private function checkOrphanReconciliation(): int
    {
        $accounts = Account::query()
            ->where('is_active', true)
            ->whereIn('api_system_id', function ($q): void {
                $q->select('id')
                    ->from('api_systems')
                    ->whereIn('canonical', ['binance', 'bitget']);
            })
            ->get();

        $alerts = 0;

        foreach ($accounts as $account) {
            try {
                $alerts += $this->reconcileAccountOrphans($account);
            } catch (Throwable $exception) {
                Log::channel('jobs')->error('[SYSTEM-HEALTH] orphan reconcile threw', [
                    'account_id' => $account->id,
                    'error' => $exception->getMessage(),
                ]);

                $this->emit(
                    signal: "orphan_reconcile_threw_account_{$account->id}",
                    severity: 'medium',
                    title: "Orphan reconciliation raised an exception (account #{$account->id})",
                    detail: "Account {$account->name}: {$exception->getMessage()}. Watchdog will retry on next tick.",
                );
                $alerts++;
            }
        }

        return $alerts;
    }

    /**
     * Single-account reconciliation pass — gathers exchange + DB state,
     * delegates classification to `OrphanReconciler`, emits one Pushover
     * per (account, symbol) group of detected orphans. Returns the
     * number of alerts emitted.
     */
    private function reconcileAccountOrphans(Account $account): int
    {
        $openResp = $account->apiQueryOpenOrders();
        $exchangeOpenOrders = collect($openResp->result ?? []);

        // Algo endpoint exists on Binance only as a separate
        // call — Bitget bundles algos into apiQueryOpenOrders. The
        // additional algo pull on Bitget is harmless (returns empty)
        // but Binance's algos are otherwise invisible.
        $algoOrders = collect();
        try {
            $algoResp = $account->apiQueryAlgoOrders();
            $algoOrders = collect($algoResp->result ?? []);
        } catch (Throwable) {
            // Bitget / one-way without algos: silent skip.
        }

        $exchangePositionsResp = $account->apiQueryPositions();
        $exchangePositions = collect($exchangePositionsResp->result ?? []);

        $exchangeOrderMeta = $this->mapExchangeOrderMeta($exchangeOpenOrders, $algoOrders);

        // PHP auto-converts numeric-string array keys to int. The
        // OrphanReconciler signature wants `array<int, string>`, and
        // every downstream cancel API needs a string orderId, so
        // normalise once here rather than threading casts everywhere.
        $exchangeOpenOrderIds = array_map(static fn ($k): string => (string) $k, array_keys($exchangeOrderMeta));

        // Position-key normalisation: hedge accounts return positions
        // already keyed `SYMBOL:LONG` / `SYMBOL:SHORT` (matching the
        // local DB); one-way accounts return `SYMBOL:BOTH`, which has
        // no analogue in the local schema (direction is logical, not
        // exchange-side). Derive LONG/SHORT from `positionAmt` sign
        // so the reconciler compares apples to apples regardless of
        // mode. Without this, every real position on a one-way account
        // mis-classifies as an orphan.
        $exchangePositionKeys = [];
        foreach ($exchangePositions as $key => $p) {
            $amt = (string) ($p['positionAmt'] ?? '0');
            if (Math::equal($amt, '0')) {
                continue;
            }
            [$symbol, $side] = array_pad(explode(':', (string) $key), 2, 'BOTH');
            if ($side === 'BOTH') {
                $side = Math::gt($amt, '0') ? 'LONG' : 'SHORT';
            }
            $exchangePositionKeys[] = "{$symbol}:{$side}";
        }

        $kraiteOpen = $account->positions()->opened()->with('orders')->get();
        $kraiteOpenOrderIds = $kraiteOpen
            ->flatMap(fn ($position) => $position->orders)
            ->whereNotIn('status', ['FILLED', 'CANCELLED', 'EXPIRED'])
            ->pluck('exchange_order_id')
            ->filter()
            ->map(fn ($id): string => (string) $id)
            ->all();

        $kraitePositionKeys = $kraiteOpen
            ->map(fn ($p): string => $p->parsed_trading_pair.':'.$p->direction)
            ->all();

        $matchWindow = (int) config(
            'kraite.health_watchdog.orphan_kraite_match_window_minutes',
            60
        );

        $kraiteRecentlyClosedOrderIds = $account->positions()
            ->whereIn('status', ['closed', 'cancelled', 'failed'])
            ->where('updated_at', '>=', now()->subMinutes($matchWindow))
            ->with('orders')
            ->get()
            ->flatMap(fn ($p) => $p->orders)
            ->pluck('exchange_order_id')
            ->filter()
            ->map(fn ($id): string => (string) $id)
            ->all();

        // In-flight guard: when any position is in a transitional
        // lifecycle state, its limit ladder is mid-placement and
        // local Order rows lag the exchange by a few seconds while
        // each API ack writes back the `exchange_order_id`. Pass the
        // flag through so the classifier suppresses order-orphan
        // detection for this tick.
        $hasInflightPositions = $kraiteOpen
            ->whereIn('status', ['new', 'opening', 'cancelling', 'syncing'])
            ->isNotEmpty();

        $report = OrphanReconciler::reconcile(
            exchangeOpenOrderIds: $exchangeOpenOrderIds,
            exchangePositionKeys: $exchangePositionKeys,
            kraiteOpenOrderIds: $kraiteOpenOrderIds,
            kraitePositionKeys: $kraitePositionKeys,
            kraiteRecentlyClosedOrderIds: $kraiteRecentlyClosedOrderIds,
            allowOtherOrders: $account->allow_other_orders,
            allowOtherPositions: $account->allow_other_positions,
            hasInflightPositions: $hasInflightPositions,
        );

        if ($report->isEmpty()) {
            return 0;
        }

        $alerts = 0;

        // Dual cooldown gate. During a global operator cooldown
        // (`Kraite.is_cooling_down` — set by `kraite:cooldown` for
        // deploys / maintenance) OR a per-exchange instability cooldown
        // (`ApiSystem.cooldown_until` — set by ApiRequestLogObserver on
        // 503/504 spikes), orphan cancel/close mutations are
        // suppressed. Detection still runs and we still emit one
        // Pushover per (account, symbol) group so the operator knows
        // an orphan exists and is awaiting cooldown lift. Same
        // reasoning as PlaceMarketOrderJob's cooldown gate: piling
        // additional API calls onto a shaky exchange or onto an
        // explicitly-paused system amplifies the very condition the
        // cooldown exists to drain.
        $globalCooldown = (bool) Kraite::first()?->is_cooling_down;
        $apiCooldown = $account->apiSystem->inCooldown();
        $skipMutations = $globalCooldown || $apiCooldown;
        $cooldownReason = $globalCooldown
            ? 'global operator cooldown'
            : ($apiCooldown ? "{$account->apiSystem->canonical} API instability cooldown" : null);

        $ordersBySymbol = collect($report->ordersToCancel)
            ->groupBy(fn (string $orderId): string => $exchangeOrderMeta[$orderId]['symbol'] ?? 'UNKNOWN');

        foreach ($ordersBySymbol as $symbol => $orderIds) {
            $idsArray = $orderIds->all();
            $cancelled = [];
            $failed = [];

            if (! $skipMutations) {
                foreach ($idsArray as $orderId) {
                    $orderId = (string) $orderId;
                    $meta = $exchangeOrderMeta[$orderId] ?? null;
                    if ($meta === null) {
                        continue;
                    }

                    try {
                        $this->cancelOrphanOrder($account, $orderId, $symbol, (bool) $meta['is_algo']);
                        $cancelled[] = $orderId;
                    } catch (Throwable $exception) {
                        $failed[] = "{$orderId} ({$exception->getMessage()})";
                        Log::channel('jobs')->error('[SYSTEM-HEALTH] orphan cancel threw', [
                            'account_id' => $account->id,
                            'orderId' => $orderId,
                            'error' => $exception->getMessage(),
                        ]);
                    }
                }
            }

            $this->emit(
                signal: "orphan_orders_account_{$account->id}_{$symbol}",
                severity: 'high',
                title: $skipMutations
                    ? "Orphan exchange orders detected on {$account->name} / {$symbol} (cleanup skipped — {$cooldownReason})"
                    : "Orphan exchange orders cleaned on {$account->name} / {$symbol}",
                detail: $skipMutations
                    ? sprintf(
                        'Detected %d orphan order(s); cleanup deferred until %s lifts. allow_other_orders=%s, match_window=%dmin. Detected: %s.',
                        count($idsArray),
                        $cooldownReason,
                        $account->allow_other_orders ? 'true' : 'false',
                        $matchWindow,
                        implode(', ', array_map('strval', $idsArray)),
                    )
                    : sprintf(
                        'Cancelled %d order(s); %d failed. allow_other_orders=%s, match_window=%dmin. Cancelled: %s. Failed: %s.',
                        count($cancelled),
                        count($failed),
                        $account->allow_other_orders ? 'true' : 'false',
                        $matchWindow,
                        $cancelled === [] ? 'none' : implode(', ', $cancelled),
                        $failed === [] ? 'none' : implode(' | ', $failed),
                    ),
            );
            $alerts++;
        }

        foreach ($report->positionsToClose as $key) {
            [$symbol, $direction] = array_pad(explode(':', $key), 2, null);
            $closed = false;
            $error = null;

            // Resolve the absolute position quantity from the live
            // snapshot — local validators hard-require `quantity` even
            // when `closePosition=true` makes the field advisory at the
            // exchange level. The lookup mirrors the position-key
            // normalisation we did earlier (one-way mode reports
            // `BOTH`, hedge reports the explicit side).
            $positionQuantity = $this->resolvePositionQuantity(
                $exchangePositions,
                $symbol,
                $direction,
                $account->isHedgeMode(),
            );

            if (! $skipMutations) {
                try {
                    $this->closeOrphanPosition($account, $symbol, $direction, $positionQuantity);
                    $closed = true;
                } catch (Throwable $exception) {
                    $error = $exception->getMessage();
                    Log::channel('jobs')->error('[SYSTEM-HEALTH] orphan close threw', [
                        'account_id' => $account->id,
                        'key' => $key,
                        'error' => $error,
                    ]);
                }
            }

            $this->emit(
                signal: "orphan_position_account_{$account->id}_{$key}",
                severity: 'high',
                title: $skipMutations
                    ? "Orphan exchange position {$key} detected on {$account->name} (cleanup skipped — {$cooldownReason})"
                    : "Orphan exchange position {$key} on {$account->name}",
                detail: $skipMutations
                    ? "Detected during {$cooldownReason}; auto-close deferred until cooldown lifts. Operator can intervene manually if needed."
                    : ($closed
                        ? 'Auto-closed via reduce-only MARKET (allow_other_positions=false).'
                        : "Auto-close FAILED: {$error}. Operator must intervene manually."),
            );
            $alerts++;
        }

        return $alerts;
    }

    /**
     * Cancel a single orphan order via the per-exchange API client.
     * Bypasses the Order model entirely — we only need symbol + id
     * for both regular and algo cancellation primitives.
     */
    private function cancelOrphanOrder(Account $account, string $orderId, string $symbol, bool $isAlgo): void
    {
        $properties = new ApiProperties;
        $properties->set('account', $account);

        if ($isAlgo) {
            $properties->set('options.symbol', $symbol);
            $properties->set('options.algoId', $orderId);
            $account->withApi()->cancelAlgoOrder($properties);

            return;
        }

        $properties->set('options.symbol', $symbol);
        $properties->set('options.orderId', $orderId);
        $account->withApi()->cancelOrder($properties);
    }

    /**
     * Close an orphan position via reduce-only MARKET. The opposing
     * side flattens the underlying exposure regardless of mode (hedge
     * or one-way) — Binance + Bitget both accept this primitive
     * with `closePosition=true` so the engine ignores the qty and
     * flat-closes whatever it finds.
     */
    private function closeOrphanPosition(Account $account, string $symbol, ?string $direction, string $quantity): void
    {
        if ($direction === null) {
            throw new \RuntimeException("Orphan position key missing direction: {$symbol}");
        }

        if ($quantity === '0' || $quantity === '') {
            // Race against snapshot: position dissolved between read
            // and close. Treat as already-closed.
            return;
        }

        // Binance's `closePosition=true` is a STOP/TP-algo-only flag
        // (`-4136 Target strategy invalid for orderType MARKET`). The
        // correct primitive for a flat-close MARKET is the explicit
        // quantity + side reversal:
        //   - LONG flat → side=SELL, quantity=|positionAmt|
        //   - SHORT flat → side=BUY,  quantity=|positionAmt|
        // Hedge mode encodes intent via `positionSide`; one-way mode
        // needs `reduceOnly=true` so the order doesn't accidentally
        // open a reverse position when the slot is already flat.
        $properties = new ApiProperties;
        $properties->set('account', $account);
        $properties->set('options.symbol', $symbol);
        $properties->set('options.type', 'MARKET');
        $properties->set('options.side', $direction === 'LONG' ? 'SELL' : 'BUY');
        $properties->set('options.quantity', $quantity);

        if ($account->isHedgeMode()) {
            $properties->set('options.positionSide', $direction);
        } else {
            $properties->set('options.reduceOnly', 'true');
        }

        $account->withApi()->placeOrder($properties);
    }

    /**
     * Walk the live exchange-positions snapshot to find the absolute
     * quantity for a given orphan key. Hedge accounts key snapshots
     * by explicit side (`SYMBOL:LONG` / `SYMBOL:SHORT`); one-way
     * accounts key by `SYMBOL:BOTH` regardless of direction. Returns
     * the absolute value as a string suitable for the placeOrder
     * `quantity` field. Returns "0" if the position has already
     * dissolved between the snapshot read and this call (rare race;
     * `closePosition=true` is still safe — Binance ignores quantity
     * when the flag is set, and our local validator only checks the
     * field is present and non-empty).
     */
    private function resolvePositionQuantity(
        Collection $exchangePositions,
        string $symbol,
        ?string $direction,
        bool $isHedgeMode,
    ): string {
        $expectedKey = $isHedgeMode
            ? "{$symbol}:{$direction}"
            : "{$symbol}:BOTH";

        $row = $exchangePositions->get($expectedKey);

        if ($row === null) {
            return '0';
        }

        $amt = (string) ($row['positionAmt'] ?? '0');

        // Math::abs() doesn't exist; idiomatic absolute via subtract-
        // from-zero when negative. Keeps decimal precision intact for
        // the placeOrder `quantity` field — `(float)` would round long
        // tails on tokens with high precision (PEPE-class et al).
        $abs = Math::lt($amt, '0')
            ? Math::sub('0', $amt)
            : $amt;

        return Math::gt($abs, '0') ? $abs : '0';
    }

    /**
     * Build a map of exchange order id → metadata (symbol, is_algo)
     * so the cleanup layer knows which API primitive to call without
     * a second exchange round-trip.
     *
     * @param  Collection<int, array<string, mixed>>  $openOrders
     * @param  Collection<int, array<string, mixed>>  $algoOrders
     * @return array<string, array{symbol: string, is_algo: bool}>
     */
    private function mapExchangeOrderMeta(
        Collection $openOrders,
        Collection $algoOrders,
    ): array {
        $map = [];

        foreach ($openOrders as $o) {
            $id = $o['orderId'] ?? null;
            if ($id !== null) {
                $map[(string) $id] = [
                    'symbol' => (string) ($o['symbol'] ?? 'UNKNOWN'),
                    'is_algo' => false,
                ];
            }
        }

        foreach ($algoOrders as $a) {
            $id = $a['algoId'] ?? ($a['orderId'] ?? null);
            if ($id !== null) {
                $map[(string) $id] = [
                    'symbol' => (string) ($a['symbol'] ?? 'UNKNOWN'),
                    'is_algo' => true,
                ];
            }
        }

        return $map;
    }

    /**
     * Shared eligibility predicate used by every per-symbol check.
     * A symbol is "eligible" when it's either fully tradeable (passes
     * the strict fourteen-column gate) OR carries at least one open
     * position. This keeps alert volume actionable: we don't watchdog
     * every symbol in the universe, just the ones the bot is actually
     * touching today.
     */
    private function eligibleExchangeSymbolsQuery(): Builder
    {
        $openedStatuses = (new Position)->openedStatuses();

        return ExchangeSymbol::query()
            ->where(function ($q) use ($openedStatuses): void {
                $q->tradeable()
                    ->orWhereExists(fn ($p) => $p->select(DB::raw(1))
                        ->from('positions')
                        ->whereColumn('positions.exchange_symbol_id', 'exchange_symbols.id')
                        ->whereIn('positions.status', $openedStatuses));
            });
    }

    /**
     * Stale-`syncing` positions wedge detector.
     *
     * SyncPositionOrdersJob flips a position to `syncing` inside computeApiable
     * and only flips it back to `active` in complete() on the success path.
     * If every order's sync throws, the framework's exception/retry chain
     * runs and intentionally leaves the position in `syncing` for visibility
     * (see SyncPositionOrdersJob.php:65-72). When the retry chain exhausts
     * (MaxRetriesReached → step Failed) the position stays in `syncing`
     * indefinitely — the reactive cron's PrepareSyncOrdersJob early-returns
     * because the position isn't `active`, and the 5-minute drift spotter
     * also only audits `active` positions. The wedge has no automated
     * recovery surface; operators have to notice and intervene manually.
     *
     * This check finds those wedged rows and alerts. We deliberately do NOT
     * auto-flip back to `active` — if the underlying orders are genuinely
     * broken, an auto-flip just re-wedges on the next sync. Surfacing the
     * problem to ops is the right action; recovery is operator-driven.
     *
     * Filter: position.status = 'syncing' AND updated_at older than
     * STALE_SYNCING_POSITION_MINUTES AND no live (non-terminal)
     * PrepareSyncOrdersJob/SyncPositionOrdersJob step exists for that
     * position. The "no live step" predicate distinguishes a long-running
     * legitimate sync (still progressing) from a wedge with no in-flight
     * worker.
     */
    private function checkStaleSyncingPositions(): int
    {
        $threshold = now()->subMinutes(self::STALE_SYNCING_POSITION_MINUTES);

        $candidates = Position::query()
            ->where('status', 'syncing')
            ->where('updated_at', '<', $threshold)
            ->get(['id', 'account_id', 'updated_at']);

        if ($candidates->isEmpty()) {
            return 0;
        }

        $terminalStates = Step::terminalStepStates();
        $candidateClasses = [PrepareSyncOrdersJob::class, AtomicSyncPositionOrdersJob::class];

        $alerts = 0;

        foreach ($candidates as $position) {
            $hasLiveStep = Step::query()
                ->whereIn('class', $candidateClasses)
                ->whereNotIn('state', $terminalStates)
                ->where(static function ($query) use ($position): void {
                    $query->where(static function ($qq) use ($position): void {
                        $qq->where('relatable_type', Position::class)
                            ->where('relatable_id', $position->id);
                    })->orWhereJsonContains('arguments->positionId', $position->id);
                })
                ->exists();

            if ($hasLiveStep) {
                continue;
            }

            $age = (int) now()->diffInMinutes($position->updated_at, true);

            $this->emit(
                signal: "stale_syncing_position_{$position->id}",
                severity: 'high',
                title: "Position #{$position->id} wedged in 'syncing'",
                detail: "Position #{$position->id} (account #{$position->account_id}) has been in 'syncing' for {$age}min (threshold: ".self::STALE_SYNCING_POSITION_MINUTES.'min) with no live PrepareSyncOrdersJob/SyncPositionOrdersJob step. The reactive sync cron and drift spotter both skip non-active positions, so this row will not auto-recover. Operator action required: investigate the failed sync chain and either flip the position back to active (if orders are healthy) or run a targeted recovery.',
            );
            $alerts++;
        }

        return $alerts;
    }

    /**
     * User-data daemon dead-letter alert.
     *
     * `StreamBinanceUserDataCommand::stashFrameToDisk` writes a JSONL line
     * to `storage/logs/user-data-deadletter-YYYY-MM-DD.log` whenever
     * Step::create throws inside the ReactPHP message handler (DB blip,
     * observer-thrown exception, validation error). The current code logs
     * to the `user-data` channel and continues — the operator only sees
     * the failure if they grep logs.
     *
     * This check converts the silent log entry into a Pushover-grade
     * alert. Today's file existing AND non-empty fires
     * `user_data_deadletter_active` — the throttle key (5-min per-signal
     * cache) bounds the noise even if a single bug produces many
     * dead-letter entries in one cycle. Operator decides whether to
     * inspect the JSONL and replay (no replay command yet — the file is
     * idempotent-safe to manually replay through `api_data_stream.idempotency_key`).
     */
    private function checkUserDataDeadLetters(): int
    {
        $path = storage_path('logs/user-data-deadletter-'.now()->format('Y-m-d').'.log');

        if (! is_file($path)) {
            return 0;
        }

        $size = (int) (@filesize($path) ?: 0);
        if ($size === 0) {
            return 0;
        }

        // Approximate entry count via line count; capped read so a huge
        // file doesn't pull bytes into memory. Any non-zero size proves
        // at least one frame failed to dispatch — that's the operational
        // signal we care about.
        $lineCount = 0;
        $handle = @fopen($path, 'r');
        if (is_resource($handle)) {
            while (($line = fgets($handle)) !== false) {
                if (mb_trim($line) !== '') {
                    $lineCount++;
                }
                if ($lineCount >= 1000) {
                    break;
                }
            }
            fclose($handle);
        }

        $countLabel = $lineCount >= 1000 ? '1000+' : (string) $lineCount;

        $this->emit(
            signal: 'user_data_deadletter_active',
            severity: 'high',
            title: 'User-data daemon dead-lettered frame(s) today',
            detail: "Today's deadletter file ({$path}) has {$countLabel} entries (~{$size} bytes). The user-data daemon failed to dispatch one or more frames into Step::create — root cause is in user-data channel logs (DB blip / observer throw / validation). Each line is a JSONL with timestamp + account_id + payload + error; replay is idempotent via `api_data_stream.idempotency_key` once the underlying issue is fixed.",
        );

        return 1;
    }

    /**
     * Single emit path so every check shares the same notification
     * surface (one canonical) with the same per-signal throttle. The
     * `signal` value is the per-signal cache key; pick a stable string
     * (e.g. `indicator_stale_BTCUSDT`) so the same failure dedupes
     * across runs and a different failure gets its own throttle bucket.
     */
    private function emit(string $signal, string $severity, string $title, string $detail): void
    {
        $this->verboseWarn("[{$severity}] {$signal}: {$title}");

        try {
            NotificationService::send(
                user: Kraite::admin(),
                canonical: 'system_health_alert',
                referenceData: [
                    'signal' => $signal,
                    'severity' => $severity,
                    'title' => $title,
                    'detail' => $detail,
                    'detected_at' => now()->toIso8601String(),
                ],
                cacheKeys: ['signal' => $signal],
            );
        } catch (Throwable $exception) {
            Log::channel('jobs')->error('[SYSTEM-HEALTH] alert dispatch failed', [
                'signal' => $signal,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
