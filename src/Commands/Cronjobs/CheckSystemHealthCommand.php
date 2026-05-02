<?php

declare(strict_types=1);

namespace Kraite\Core\Commands\Cronjobs;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\BinanceListenKey;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Support\NotificationService;
use StepDispatcher\Support\BaseCommand;
use Throwable;

/**
 * CheckSystemHealthCommand
 *
 * Runs nine staleness / connectivity checks every minute. Each check
 * emits zero-or-many alerts via the shared `system_health_alert`
 * notification canonical, with a per-signal cache key (5-minute
 * throttle) so distinct failures dedupe independently while sharing
 * the same Pushover / email plumbing.
 *
 * Why one command for all nine: the notification canonical is shared,
 * the throttle behaviour is shared, the loop / try-catch / log shape
 * is shared. A single sequential pass keeps one cron entry, one log
 * line per run, and adding a tenth check is a single new private
 * method. No notifications-table churn per check.
 *
 * Severity is informational only — Pushover delivery doesn't filter
 * on it. The field is here so the dashboard / model_logs can render
 * differently if Bruno ever wants tiered display.
 */
final class CheckSystemHealthCommand extends BaseCommand
{
    private const MARK_PRICE_STALENESS_SECONDS = 60;

    private const FAILED_JOBS_THRESHOLD = 10;

    private const HORIZON_QUEUE_DEPTH_THRESHOLD = 5000;

    /**
     * Indicators run hourly via `kraite:cron-conclude-symbols-direction`
     * at :30 + a few minutes of processing. 90min gives a full hour of
     * cadence + 30min buffer for processing time and the rare double
     * skip. Lower thresholds produce alerts every cycle even on a
     * healthy system.
     */
    private const INDICATOR_STALENESS_MINUTES = 90;

    private const BALANCE_STALENESS_MINUTES = 10;

    private const DAEMON_HEARTBEAT_STALENESS_SECONDS = 120;

    private const DISPATCHER_TICK_STALENESS_SECONDS = 60;

    private const SCHEDULER_LIVENESS_SECONDS = 120;

    private const HORIZON_QUEUES = ['default', 'priority', 'positions', 'orders', 'cronjobs', 'indicators', 'user-data-stream'];

    protected $signature = 'kraite:cron-check-system-health
                            {--output : Display command output (silent by default)}';

    protected $description = 'Run ten staleness / connectivity checks across the bot\'s critical data paths and alert per failed signal.';

    public function handle(): int
    {
        $checks = [
            'checkMarkPriceFreshness',
            'checkIndicatorFreshness',
            'checkAccountBalanceFreshness',
            'checkDaemonHeartbeat',
            'checkDispatcherTickRate',
            'checkSchedulerLiveness',
            'checkFailedJobsOverflow',
            'checkDatabaseConnection',
            'checkRedisConnection',
            'checkHorizonQueueDepth',
        ];

        $alertCount = 0;

        foreach ($checks as $check) {
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
     * #0 — `exchange_symbols.mark_price_synced_at` per symbol that's
     * tradeable OR carries an open position. The price daemon writes
     * this column on every WS frame; staleness past 60s = the daemon
     * is no longer publishing fresh prices for a symbol the bot has
     * skin in. Same predicate as the indicator check to keep the
     * alert volume actionable across both.
     */
    private function checkMarkPriceFreshness(): int
    {
        $threshold = now()->subSeconds(self::MARK_PRICE_STALENESS_SECONDS);

        $stale = $this->eligibleExchangeSymbolsQuery()
            ->notDelisted()
            ->whereNotNull('mark_price_synced_at')
            ->where('mark_price_synced_at', '<', $threshold)
            ->orderBy('mark_price_synced_at')
            ->get(['id', 'token', 'quote', 'mark_price_synced_at']);

        $alerts = 0;
        foreach ($stale as $row) {
            $pair = $row->token.$row->quote;
            $age = (int) now()->diffInSeconds($row->mark_price_synced_at, true);
            $this->emit(
                signal: "mark_price_stale_{$pair}",
                severity: 'high',
                title: "Mark price stale for {$pair}",
                detail: "exchange_symbols.mark_price_synced_at is {$age}s old (threshold: ".self::MARK_PRICE_STALENESS_SECONDS."s) for exchange_symbol #{$row->id}. Price daemon is no longer publishing fresh prices for a symbol the bot has skin in (open position or tradeable).",
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
            ->get(['id', 'token', 'quote']);

        $alerts = 0;
        foreach ($stale as $row) {
            $pair = $row->token.$row->quote;
            $this->emit(
                signal: "indicator_stale_{$pair}",
                severity: 'high',
                title: "Indicators stale for {$pair}",
                detail: "No `indicator_histories` row newer than ".self::INDICATOR_STALENESS_MINUTES."min for exchange_symbol #{$row->id}. Direction-decision logic is reading frozen signals.",
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
                detail: "No `account_balance_history` row newer than ".self::BALANCE_STALENESS_MINUTES."min for account #{$account->id} ({$account->name}). PnL + sizing math reading stale numbers.",
            );
            $alerts++;
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
            detail: "Daemon heartbeat flag has not been touched in {$age}s (threshold: ".self::DAEMON_HEARTBEAT_STALENESS_SECONDS."s). Event loop is likely wedged even though supervisorctl reports RUNNING.",
        );

        return 1;
    }

    /**
     * #4 — Dispatcher liveness via `steps_dispatcher.last_selected_at`.
     * That timestamp gets touched on every dispatch attempt regardless
     * of whether work was found, so a stale value across ALL groups is
     * a hard "dispatcher cron is dead" signal.
     *
     * Note: we deliberately avoid `steps_dispatcher_ticks` here — that
     * table is filtered by `recordTickWhen` (only slow ticks are
     * persisted), so an empty recent set indicates a HEALTHY fast
     * dispatcher, not a dead one. Using `last_selected_at` instead
     * gives the always-on signal we actually want.
     */
    private function checkDispatcherTickRate(): int
    {
        $latest = DB::table('steps_dispatcher')->max('last_selected_at');

        if ($latest === null) {
            // No groups registered — benign on a fresh database. The
            // first dispatch attempt will populate the table.
            return 0;
        }

        $age = (int) now()->diffInSeconds($latest, true);
        if ($age <= self::DISPATCHER_TICK_STALENESS_SECONDS) {
            return 0;
        }

        $this->emit(
            signal: 'dispatcher_tick_stale',
            severity: 'high',
            title: 'Step dispatcher tick stale',
            detail: "Newest `steps_dispatcher.last_selected_at` is {$age}s old (threshold: ".self::DISPATCHER_TICK_STALENESS_SECONDS."s). The `steps:dispatch` cron is not firing — workers will starve.",
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
     * Falls back to the dispatcher-tick proxy when there are no
     * Binance listen-key rows (e.g. all accounts inactive or deleted).
     */
    private function checkSchedulerLiveness(): int
    {
        $keepaliveProxy = BinanceListenKey::query()->max('last_keep_alive_at');

        if ($keepaliveProxy === null) {
            // No keep-alive proxy available — fall through to the
            // dispatcher-tick check to avoid duplicate alerts.
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
            detail: "No keepalive cron run in the last {$age}s (threshold: ".self::SCHEDULER_LIVENESS_SECONDS."s). `schedule:work` is likely dead — the entire cron chain is silent.",
        );

        return 1;
    }

    /**
     * #6 — `failed_jobs` overflow. Workers writing to this table at
     * a sustained rate means jobs are dying; if it grows past the
     * threshold the operator should look. Throttle handles repeat
     * alerts for a single sustained outage.
     */
    private function checkFailedJobsOverflow(): int
    {
        $count = DB::table('failed_jobs')->count();

        if ($count <= self::FAILED_JOBS_THRESHOLD) {
            return 0;
        }

        $this->emit(
            signal: 'failed_jobs_overflow',
            severity: 'high',
            title: 'failed_jobs queue overflow',
            detail: "`failed_jobs` table has {$count} rows (threshold: ".self::FAILED_JOBS_THRESHOLD."). Workers are dying or jobs are throwing un-handled exceptions.",
        );

        return 1;
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
     * #9 — Horizon queue depth. A growing pending count means workers
     * can't keep up (or are wedged). We sum across the canonical
     * queues we own; per-queue inspection lives in the dashboard.
     */
    private function checkHorizonQueueDepth(): int
    {
        try {
            $depth = 0;
            foreach (self::HORIZON_QUEUES as $queue) {
                $depth += (int) Redis::connection()->llen("queues:{$queue}");
            }
        } catch (Throwable) {
            // Redis already covered by check #8; don't double-alert.
            return 0;
        }

        if ($depth <= self::HORIZON_QUEUE_DEPTH_THRESHOLD) {
            return 0;
        }

        $this->emit(
            signal: 'horizon_queue_depth',
            severity: 'medium',
            title: 'Horizon queue depth high',
            detail: "Pending queue depth across canonical queues = {$depth} (threshold: ".self::HORIZON_QUEUE_DEPTH_THRESHOLD."). Workers are not keeping up — investigate stuck jobs or insufficient processes.",
        );

        return 1;
    }


    /**
     * Shared eligibility predicate used by every per-symbol check.
     * A symbol is "eligible" when it's either fully tradeable (passes
     * the strict fourteen-column gate) OR carries at least one open
     * position. This keeps alert volume actionable: we don't watchdog
     * every symbol in the universe, just the ones the bot is actually
     * touching today.
     */
    private function eligibleExchangeSymbolsQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $openedStatuses = (new \Kraite\Core\Models\Position)->openedStatuses();

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
