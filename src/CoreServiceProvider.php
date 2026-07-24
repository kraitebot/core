<?php

declare(strict_types=1);

namespace Kraite\Core;

use DateTimeInterface;
use Dotenv\Dotenv;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Kraite\Core\Commands\BackfillOriginalPricesCommand;
use Kraite\Core\Commands\Backtest\AutomaticBacktestCommand;
use Kraite\Core\Commands\Backtest\BacktestTokenCommand;
use Kraite\Core\Commands\CloneCommand;
use Kraite\Core\Commands\CooldownCommand;
use Kraite\Core\Commands\Cronjobs\AnalyseBscsCommand;
use Kraite\Core\Commands\Cronjobs\CheckBinanceListenKeysStaleCommand;
use Kraite\Core\Commands\Cronjobs\CheckDriftsCommand;
use Kraite\Core\Commands\Cronjobs\CheckSystemHealthCommand;
use Kraite\Core\Commands\Cronjobs\ComputeMarketRegimeCommand;
use Kraite\Core\Commands\Cronjobs\ConcludeSymbolsDirectionCommand;
use Kraite\Core\Commands\Cronjobs\CreatePositionsCommand;
use Kraite\Core\Commands\Cronjobs\CronPurgePositionTrailsCommand;
use Kraite\Core\Commands\Cronjobs\DetectMarketShockCommand;
use Kraite\Core\Commands\Cronjobs\DisableVolatileTokensCommand;
use Kraite\Core\Commands\Cronjobs\FetchKlinesCommand;
use Kraite\Core\Commands\Cronjobs\FlushDispatcherSaturationCommand;
use Kraite\Core\Commands\Cronjobs\MonitorNarrateCommand;
use Kraite\Core\Commands\Cronjobs\OptimizeBreadcrumbTablesCommand;
use Kraite\Core\Commands\Cronjobs\PurgeCandlesCommand;
use Kraite\Core\Commands\Cronjobs\PurgeFailedBacktestedKlinesCommand;
use Kraite\Core\Commands\Cronjobs\PurgeModelLogsCommand;
use Kraite\Core\Commands\Cronjobs\PurgeOldDataCommand;
use Kraite\Core\Commands\Cronjobs\RefreshBinanceListenKeysCommand;
use Kraite\Core\Commands\Cronjobs\RefreshExchangeSymbolsCommand;
use Kraite\Core\Commands\Cronjobs\RenewSubscriptionsCommand;
use Kraite\Core\Commands\Cronjobs\StoreAccountsBalancesCommand;
use Kraite\Core\Commands\Cronjobs\SyncOrdersCommand;
use Kraite\Core\Commands\Cronjobs\UpsertPnlsCommand;
use Kraite\Core\Commands\Daemons\StreamBinancePricesCommand;
use Kraite\Core\Commands\Daemons\StreamBinanceUserDataCommand;
use Kraite\Core\Commands\DispatchDaemonCommand;
use Kraite\Core\Commands\Fleet\ReportFleetMetricsCommand;
use Kraite\Core\Commands\FreezeCommand;
use Kraite\Core\Commands\Ingestion\IsEligibleCommand;
use Kraite\Core\Commands\RecoverPositionsCommand;
use Kraite\Core\Commands\SafeToRestartCommand;
use Kraite\Core\Commands\Tests\TestNotificationCommand;
use Kraite\Core\Commands\Tests\ThrottlerStressTestCommand;
use Kraite\Core\Commands\UnfreezeCommand;
use Kraite\Core\Commands\UpdateRecvwindowSafetyDurationCommand;
use Kraite\Core\Commands\VerifyFleetTopologyCommand;
use Kraite\Core\Commands\WarmupCommand;
use Kraite\Core\Contracts\ProductionDatabaseCloneGateway;
use Kraite\Core\Events\UserEmailConfirmed;
use Kraite\Core\Listeners\AttachPrivateBetaCoupon;
use Kraite\Core\Listeners\NotificationLogListener;
use Kraite\Core\Listeners\SendStaleStepsNotification;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\AccountBalanceHistory;
use Kraite\Core\Models\ApiRequestLog;
use Kraite\Core\Models\ApiSnapshot;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\ForbiddenHostname;
use Kraite\Core\Models\Indicator;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\ModelLog;
use Kraite\Core\Models\NotificationLog;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\SlowQuery;
use Kraite\Core\Models\Symbol;
use Kraite\Core\Models\User;
use Kraite\Core\Observers\AccountBalanceHistoryObserver;
use Kraite\Core\Observers\AccountObserver;
use Kraite\Core\Observers\ApiRequestLogObserver;
use Kraite\Core\Observers\ApiSnapshotObserver;
use Kraite\Core\Observers\ApiSystemObserver;
use Kraite\Core\Observers\ExchangeSymbolObserver;
use Kraite\Core\Observers\ForbiddenHostnameObserver;
use Kraite\Core\Observers\IndicatorObserver;
use Kraite\Core\Observers\KraiteObserver;
use Kraite\Core\Observers\NotificationLogObserver;
use Kraite\Core\Observers\OrderObserver;
use Kraite\Core\Observers\PositionObserver;
use Kraite\Core\Observers\SymbolObserver;
use Kraite\Core\Observers\UserObserver;
use Kraite\Core\Policies\AccountPolicy;
use Kraite\Core\Support\Database\SshProductionDatabaseCloneGateway;
use Kraite\Core\Support\FreezeMode;
use Kraite\Core\Support\NotificationService;
use Kraite\Core\Support\StepRouter;
use Psr\Http\Message\RequestInterface;
use Schema;
use StepDispatcher\Events\StaleStepsDetected;
use StepDispatcher\Models\Step;
use StepDispatcher\Support\StepDispatcher;
use Throwable;

final class CoreServiceProvider extends ServiceProvider
{
    public function boot()
    {
        Http::globalRequestMiddleware(static function (RequestInterface $request): RequestInterface {
            FreezeMode::assertExternalTrafficAllowed('Outbound HTTP request');

            return $request;
        });

        Event::listen(MessageSending::class, static function (): ?bool {
            return FreezeMode::isActive() ? false : null;
        });

        $this->commands([
            SafeToRestartCommand::class,
            RecoverPositionsCommand::class,
            BackfillOriginalPricesCommand::class,
            UpdateRecvwindowSafetyDurationCommand::class,
            VerifyFleetTopologyCommand::class,
            AutomaticBacktestCommand::class,
            BacktestTokenCommand::class,
            AnalyseBscsCommand::class,
            CheckDriftsCommand::class,
            MonitorNarrateCommand::class,
            ComputeMarketRegimeCommand::class,
            DetectMarketShockCommand::class,
            ConcludeSymbolsDirectionCommand::class,
            CreatePositionsCommand::class,
            RenewSubscriptionsCommand::class,
            DisableVolatileTokensCommand::class,
            FetchKlinesCommand::class,
            CronPurgePositionTrailsCommand::class,
            PurgeCandlesCommand::class,
            PurgeFailedBacktestedKlinesCommand::class,
            PurgeModelLogsCommand::class,
            PurgeOldDataCommand::class,
            OptimizeBreadcrumbTablesCommand::class,
            FlushDispatcherSaturationCommand::class,
            RefreshExchangeSymbolsCommand::class,
            StoreAccountsBalancesCommand::class,
            StreamBinancePricesCommand::class,
            StreamBinanceUserDataCommand::class,
            SyncOrdersCommand::class,
            UpsertPnlsCommand::class,
            RefreshBinanceListenKeysCommand::class,
            CheckBinanceListenKeysStaleCommand::class,
            CheckSystemHealthCommand::class,
            IsEligibleCommand::class,
            TestNotificationCommand::class,
            ThrottlerStressTestCommand::class,
            CooldownCommand::class,
            DispatchDaemonCommand::class,
            WarmupCommand::class,
            ReportFleetMetricsCommand::class,
            FreezeCommand::class,
            UnfreezeCommand::class,
            CloneCommand::class,
        ]);

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'kraite');

        // Authorization policies. The connectivity endpoints touch account
        // credentials and leak per-server state, so they authorize against
        // account ownership (or admin) rather than mere authentication.
        Gate::policy(Account::class, AccountPolicy::class);

        // Load API routes with /api prefix
        $this->app->router->group([
            'prefix' => 'api',
            'middleware' => ['web'],
        ], static function ($router) {
            require __DIR__.'/../routes/api.php';
        });

        $this->publishes([
            __DIR__.'/../config/kraite.php' => config_path('kraite.php'),
        ]);

        Model::automaticallyEagerLoadRelationships();
        Model::unguard();

        // Register NotificationLogListener as event subscriber
        Event::subscribe(NotificationLogListener::class);

        // Bridge step-dispatcher stall detection into Kraite's notification stack.
        Event::listen(StaleStepsDetected::class, [SendStaleStepsNotification::class, 'handle']);

        // Cross-app: kraite.com fires `UserEmailConfirmed` from
        // `PrivateBetaController@verify`; the listener implements
        // ShouldQueue so the work serializes onto the shared Redis
        // queue and the ingestion Horizon workers pick it up. The
        // listener owns the private-beta-coupon attach (gated on
        // `kraite.in_private_beta`).
        Event::listen(
            UserEmailConfirmed::class,
            [AttachPrivateBetaCoupon::class, 'handle'],
        );

        AccountBalanceHistory::observe(AccountBalanceHistoryObserver::class);
        Account::observe(AccountObserver::class);
        ApiRequestLog::observe(ApiRequestLogObserver::class);
        ApiSnapshot::observe(ApiSnapshotObserver::class);
        ApiSystem::observe(ApiSystemObserver::class);
        ExchangeSymbol::observe(ExchangeSymbolObserver::class);
        Indicator::observe(IndicatorObserver::class);
        Kraite::observe(KraiteObserver::class);
        NotificationLog::observe(NotificationLogObserver::class);
        Order::observe(OrderObserver::class);
        Position::observe(PositionObserver::class);
        ForbiddenHostname::observe(ForbiddenHostnameObserver::class);
        Symbol::observe(SymbolObserver::class);
        User::observe(UserObserver::class);

        // Override Resend API key from Kraite singleton (encrypted DB source of truth).
        $this->app->booted(function (): void {
            try {
                $engine = Kraite::first();

                if ($engine?->resend_api_key) {
                    config(['services.resend.key' => $engine->resend_api_key]);
                }
            } catch (Throwable) {
                // Table may not exist during migrations or testing bootstrap.
            }
        });

        // Register slow query listener
        $this->registerSlowQueryListener();

        // Clear the per-job Step context once each queued job finishes, so
        // the next job (or a subsequent HTTP/CLI cycle sharing this worker
        // process) starts with no stale relatable stamp on attribute-change
        // logs. Both success (JobProcessed) and failure (JobFailed) paths
        // fire — covers every handle() exit regardless of the internal
        // shouldExitEarly branch taken.
        Queue::after(static function (): void {
            ModelLog::setCurrentStep(null);
        });

        Queue::failing(static function (): void {
            ModelLog::setCurrentStep(null);
        });

        // Dispatch-time queue routing. The framework calls this hook in
        // DispatchesJobs::dispatchSingleStep() right before pushing onto
        // Redis. The router (Kraite\Core\Support\StepRouter) inspects
        // the step's logical queue + the active ban set for the related
        // (account, api_system) pair and returns the physical per-hostname
        // queue (e.g. eos-positions). Throws NoCleanWorkerException with
        // a side-effect cascade (account deactivation + portfolio-at-risk
        // notification) when every eligible worker IP is blacklisted on
        // the target exchange.
        //
        // Registered late in boot() so the Server / Account / ForbiddenHostname
        // models + the kraite.horizon.workers config are all available
        // by the time the first dispatch tick can fire.
        StepDispatcher::setQueueResolver(static function (Step $step): ?string {
            return app(StepRouter::class)->resolveQueueName($step);
        });

        $this->syncHorizonEnvironmentsFromKraiteConfig();
    }

    public function register(): void
    {
        $this->loadSharedEnvironment();

        $this->mergeConfigFrom(
            __DIR__.'/../config/kraite.php',
            'kraite'
        );

        // MUST run in register(), not boot(): the `redis` manager is a singleton
        // that snapshots config('database.redis') the first time it's resolved.
        // In a long-running Horizon worker, another provider's boot() resolves
        // Redis (for the queue) before CoreServiceProvider::boot() could inject
        // the `fleet` connection — so the worker's manager never sees it and
        // FleetMetricsRepository::write() throws "Redis connection [fleet] not
        // configured", silently killing every box's heartbeat. Registering in
        // register() (before ANY provider boot()) guarantees the config is set
        // before the manager is ever resolved. (Worked on the CLI + pheme by
        // luck of resolution order; broke on the ingestion workers — 2026-06-12.)
        $this->registerFleetRedisConnection();

        // Bind the drift-spotter contract to its production implementation.
        // The interface seam exists so tests can swap in canned reports
        // without going through the real exchange API.
        $this->app->bind(
            Support\Drift\DriftChecker::class,
            Support\Drift\DriftCheckService::class,
        );

        $this->app->bind(
            ProductionDatabaseCloneGateway::class,
            SshProductionDatabaseCloneGateway::class,
        );

        $this->app->singleton(Support\MarketRegime\BscsManager::class);
        $this->app->singleton(Trading\Exchange\ExchangeManager::class);
        $this->app->singleton(Trading\TokenSelection\TokenSelectionManager::class);
    }

    protected function loadSharedEnvironment(): void
    {
        $path = env('KRAITE_ENV_PATH', '/home/waygou');
        $file = env('KRAITE_ENV_FILE', '.env.kraite');

        if (file_exists($path.DIRECTORY_SEPARATOR.$file)) {
            Dotenv::createImmutable($path, $file)->safeLoad();
            $this->syncSharedEnvironmentConfig();
        }
    }

    protected function syncSharedEnvironmentConfig(): void
    {
        if (env('RESEND_API_KEY') !== null) {
            config(['services.resend.key' => env('RESEND_API_KEY')]);
        }

        if (env('ZEPTOMAIL_MAIL_KEY') !== null) {
            config([
                'services.zeptomail.mail_key' => env('ZEPTOMAIL_MAIL_KEY'),
                'services.zeptomail.endpoint' => env('ZEPTO_MAIL_ENDPOINT', 'https://api.zeptomail.com'),
                'services.zeptomail.timeout' => env('ZEPTO_MAIL_TIMEOUT', 30),
                'services.zeptomail.retries' => env('ZEPTO_MAIL_RETRIES', 2),
                'services.zeptomail.retry_sleep_ms' => env('ZEPTO_MAIL_RETRY_MS', 200),
                'services.zeptomail.track_opens' => env('ZEPTO_MAIL_TRACK_OPENS', false),
                'services.zeptomail.track_clicks' => env('ZEPTO_MAIL_TRACK_CLICKS', false),
            ]);
        }
    }

    protected function registerSlowQueryListener(): void
    {
        DB::listen(static function (QueryExecuted $query) {
            // Re-entry guard. The closure body itself executes queries
            // (SlowQuery::create + NotificationService::send touch
            // `slow_queries`, `notifications`, `notification_logs`); each
            // of those queries fires DB::listen again. The Str::contains
            // guard below only filters writes to `slow_queries` itself —
            // notification-table reads are NOT filtered, so a slow read
            // there would recurse into a second slow_query_detected
            // notification, looping until the cache throttle catches up.
            static $inSlowQueryHandler = false;
            if ($inSlowQueryHandler) {
                return;
            }

            $threshold = (int) config('kraite.slow_query_threshold_ms', 5000);
            if ($query->time <= $threshold) {
                return;
            }

            if (Str::contains(Str::lower($query->sql), 'slow_queries')) {
                return;
            }

            // Skip during migrations when slow_queries table may not exist yet
            if (! Schema::hasTable('slow_queries')) {
                return;
            }

            $inSlowQueryHandler = true;
            try {
                self::handleSlowQuery($query, $threshold);
            } finally {
                $inSlowQueryHandler = false;
            }
        });
    }

    private static function handleSlowQuery(QueryExecuted $query, int $threshold): void
    {

        $bindings = $query->bindings;
        foreach ($bindings as $k => $v) {
            if (! ($v instanceof DateTimeInterface)) {
                continue;
            }

            $bindings[$k] = $v->format('Y-m-d H:i:s');
        }

        $sqlFull = $query->sql;
        foreach ($bindings as $binding) {
            $val = is_null($binding) ? 'NULL' : addslashes((string) $binding);
            $wrap = is_null($binding) ? 'NULL' : "'{$val}'";
            $sqlFull = preg_replace('/\?/', $wrap, $sqlFull, limit: 1);
        }

        SlowQuery::create([
            'tick_id' => cache('current_tick_id'),
            'connection' => $query->connectionName,
            'time_ms' => (int) $query->time,
            'sql' => $query->sql,
            'sql_full' => $sqlFull,
            'bindings' => $bindings,
        ]);

        // Fresh installs and migrations can execute a slow schema query after
        // slow_queries exists but before the singleton admin row is seeded.
        // Keep the audit row above, but notification delivery cannot be built
        // until Kraite::admin() has an id=1 source record.
        if (! Schema::hasTable('kraite') || ! Kraite::query()->whereKey(1)->exists()) {
            return;
        }

        // Send notification to admin about slow query.
        //
        // Throttled per-connection via cache-based SETNX (cache_key=["connection"]
        // on the notification row). DB::listen fires on every query — without
        // a cache key, two workers hitting a slow query in the same window
        // would race past the DB exists() check and both emit. Keying on
        // connection means one alert per connection per cache_duration window
        // (default 5 min): broad enough to surface a systemic slow-query
        // problem, narrow enough that a slow read-replica query doesn't
        // silence a slow primary.
        NotificationService::send(
            user: Kraite::admin(),
            canonical: 'slow_query_detected',
            referenceData: [
                'sql_full' => $sqlFull,
                'time_ms' => (int) $query->time,
                'connection' => $query->connectionName,
                'threshold_ms' => $threshold,
            ],
            cacheKeys: ['connection' => $query->connectionName]
        );
    }

    /**
     * Compose `config('horizon.environments')` from `config('kraite.horizon')`
     * at boot time. Can't happen inline inside config/horizon.php because
     * Laravel loads config files in alphabetical order — horizon.php (h)
     * loads BEFORE kraite.php (k), so an inline transformer reading from
     * kraite.* would always see an empty array. Deferring to ServiceProvider
     * boot guarantees every config file is loaded by the time we run.
     *
     * Single-source-of-truth invariant: both Horizon (via `horizon.environments`)
     * and `StepRouter` (via `kraite.horizon.workers`) read derived views of the
     * same topology. The drift check `kraite:verify-fleet-topology` asserts the
     * derived data agrees with the `servers` table — without that, banned-IP
     * filtering silently no-ops for the drifted worker.
     */
    private function syncHorizonEnvironmentsFromKraiteConfig(): void
    {
        $workers = config('kraite.horizon.workers', []);
        $defaults = config('kraite.horizon.defaults', []);

        if (! is_array($workers) || $workers === []) {
            return;
        }

        $environments = [];
        $physicalQueues = [];
        $physicalQueueConnections = [];

        foreach ($workers as $hostname => $logicalQueues) {
            if (! is_array($logicalQueues)) {
                continue;
            }

            $supervisors = [];

            foreach ($logicalQueues as $logical => $overrides) {
                // Physical queue name: per-hostname prefix, except for the
                // hostname's own queue (logical name == hostname → no
                // double-prefixing). Matches StepRouter::buildPhysicalQueue
                // exactly so the dispatcher and Horizon agree on naming.
                $physical = $logical === $hostname ? $hostname : "{$hostname}-{$logical}";

                $supervisor = array_merge(
                    $defaults,
                    ['queue' => [$physical]],
                    is_array($overrides) ? $overrides : [],
                );
                $supervisors["{$logical}-supervisor"] = $supervisor;

                $physicalQueues[] = $physical;
                $physicalQueueConnections[$physical] = (string) ($supervisor['connection'] ?? config('queue.default', 'redis'));
            }

            $environments[$hostname] = $supervisors;
        }

        config(['horizon.environments' => $environments]);

        // Horizon matches wait thresholds by exact connection + physical
        // queue name. Keep the configured default threshold, then project it
        // onto every queue derived above so LongWaitDetected can observe the
        // queues workers actually consume.
        $waits = (array) config('horizon.waits', []);

        foreach ($physicalQueueConnections as $physicalQueue => $connection) {
            $waitKey = "{$connection}:{$physicalQueue}";
            $waits[$waitKey] ??= (int) ($waits["{$connection}:default"] ?? 60);
        }

        config(['horizon.waits' => $waits]);

        // Extend step-dispatcher.queues.valid with every derived physical
        // queue so StepObserver's saving() hook accepts them. Without this
        // the resolver writes `step.queue='eos-positions'` and the observer
        // silently demotes it to `default` because 'eos-positions' isn't
        // on the allowlist — the dispatch then pushes to the wrong queue
        // and no worker consumes it.
        $existingValid = (array) config('step-dispatcher.queues.valid', []);
        $merged = array_values(array_unique(array_merge($existingValid, $physicalQueues)));

        config(['step-dispatcher.queues.valid' => $merged]);
    }

    /**
     * Register a dedicated, UNPREFIXED Redis connection (`fleet`) for the
     * fleet-metrics heartbeat. Cloned from the app's `default` connection
     * (so it inherits whatever host / password each app already points at)
     * but with an empty key prefix and pinned to the kraite Redis database.
     *
     * Why empty prefix: the writer runs under the ingestion app and the
     * reader under the admin app, each with its own `REDIS_PREFIX`. On the
     * default connection the same logical key prefixes differently per app,
     * so the reader would never see the writer's keys. hyperion (no PHP app)
     * writes the same literal key via raw redis-cli. An empty prefix on a
     * shared connection is the only way all four producers/consumers agree
     * on the byte-for-byte key `kraite:fleet:<hostname>`.
     */
    private function registerFleetRedisConnection(): void
    {
        $default = config('database.redis.default');

        if (! is_array($default)) {
            return;
        }

        config([
            'database.redis.fleet' => array_merge($default, [
                'database' => (int) config('kraite.fleet_metrics.redis_database', 2),
                // Preserve any global redis options (cluster / persistent) but
                // force an EMPTY prefix — the literal key is the whole point.
                'options' => array_merge(
                    (array) config('database.redis.options', []),
                    (array) ($default['options'] ?? []),
                    ['prefix' => ''],
                ),
            ]),
        ]);
    }
}
