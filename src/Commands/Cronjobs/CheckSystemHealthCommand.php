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

    /**
     * Disk-pressure threshold. Below this percent of free space on
     * the root filesystem, emit a high-severity alert. Logs grow
     * faster than DB rows on this server (see horizon.log size),
     * so disk pressure shows up before any other capacity signal.
     */
    private const DISK_FREE_PERCENT_MIN = 15;

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
            // 'checkDispatcherTickRate' disabled 2026-05-03: signal
            // mismatch — `steps_dispatcher.last_selected_at` updates
            // only on root-step CREATE (via `StepsDispatcher::getNextGroup()`),
            // not on every dispatch attempt. During quiet periods
            // between minute-level crons MAX naturally exceeds 60s
            // and fires false-positive alerts even though Horizon is
            // healthy and the dispatcher is processing. Re-enable
            // after the step-dispatcher path package gains a true
            // per-tick stamp column (column on `steps_dispatcher`
            // written by every dispatch attempt regardless of work).
            'checkSchedulerLiveness',
            'checkFailedJobsOverflow',
            'checkDatabaseConnection',
            'checkRedisConnection',
            'checkHorizonQueueDepth',
            'checkOrphanReconciliation',
            'checkDiskPressure',
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

        $alerts = 0;
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

        if ($free === false || $total === false || $total <= 0) {
            return 0;
        }

        $freePercent = ($free / $total) * 100;

        if ($freePercent >= self::DISK_FREE_PERCENT_MIN) {
            return 0;
        }

        $freeGib = number_format($free / (1024 ** 3), 2);
        $totalGib = number_format($total / (1024 ** 3), 2);
        $freePercentStr = number_format($freePercent, 1);

        $this->emit(
            signal: 'disk_pressure_low',
            severity: 'high',
            title: 'Disk pressure on root filesystem',
            detail: "Root filesystem free = {$freeGib} GiB / {$totalGib} GiB ({$freePercentStr}% free, threshold: ".self::DISK_FREE_PERCENT_MIN."%). Logs and supervisor stdout writes will start failing before any other capacity signal. Investigate `du -sh /var/log /home/waygou/ingestion.kraite.com/storage/logs` and rotate / prune.",
        );

        return 1;
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
     * (default 60). Cleanup execution is deferred to a follow-up
     * iteration; this pass does detection + per (account, symbol)
     * Pushover so the operator can act on confirmed orphans before
     * we wire automatic cancel/close primitives across both
     * exchanges.
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
            if (\Kraite\Core\Support\Math::equal($amt, '0')) {
                continue;
            }
            [$symbol, $side] = array_pad(explode(':', (string) $key), 2, 'BOTH');
            if ($side === 'BOTH') {
                $side = \Kraite\Core\Support\Math::gt($amt, '0') ? 'LONG' : 'SHORT';
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

        $report = \Kraite\Core\Support\Health\OrphanReconciler::reconcile(
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

        $ordersBySymbol = collect($report->ordersToCancel)
            ->groupBy(fn (string $orderId): string => $exchangeOrderMeta[$orderId]['symbol'] ?? 'UNKNOWN');

        foreach ($ordersBySymbol as $symbol => $orderIds) {
            $idsArray = $orderIds->all();
            $cancelled = [];
            $failed = [];

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

            $this->emit(
                signal: "orphan_orders_account_{$account->id}_{$symbol}",
                severity: 'high',
                title: "Orphan exchange orders cleaned on {$account->name} / {$symbol}",
                detail: sprintf(
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

            $this->emit(
                signal: "orphan_position_account_{$account->id}_{$key}",
                severity: 'high',
                title: "Orphan exchange position {$key} on {$account->name}",
                detail: $closed
                    ? "Auto-closed via reduce-only MARKET (allow_other_positions=false)."
                    : "Auto-close FAILED: {$error}. Operator must intervene manually.",
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
        $properties = new \Kraite\Core\Support\ValueObjects\ApiProperties;
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
        $properties = new \Kraite\Core\Support\ValueObjects\ApiProperties;
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
        \Illuminate\Support\Collection $exchangePositions,
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
        $abs = \Kraite\Core\Support\Math::lt($amt, '0')
            ? \Kraite\Core\Support\Math::sub('0', $amt)
            : $amt;

        return \Kraite\Core\Support\Math::gt($abs, '0') ? $abs : '0';
    }

    /**
     * Build a map of exchange order id → metadata (symbol, is_algo)
     * so the cleanup layer knows which API primitive to call without
     * a second exchange round-trip.
     *
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $openOrders
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $algoOrders
     * @return array<string, array{symbol: string, is_algo: bool}>
     */
    private function mapExchangeOrderMeta(
        \Illuminate\Support\Collection $openOrders,
        \Illuminate\Support\Collection $algoOrders,
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
