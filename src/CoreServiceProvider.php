<?php

declare(strict_types=1);

namespace Kraite\Core;

use DateTimeInterface;
use Dotenv\Dotenv;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Kraite\Core\Commands\BackfillOriginalPricesCommand;
use Kraite\Core\Commands\Backtest\BacktestTokenCommand;
use Kraite\Core\Commands\CooldownCommand;
use Kraite\Core\Commands\Cronjobs\AnalyseBscsCommand;
use Kraite\Core\Commands\Cronjobs\CheckBinanceListenKeysStaleCommand;
use Kraite\Core\Commands\Cronjobs\CheckDriftsCommand;
use Kraite\Core\Commands\Cronjobs\CheckSystemHealthCommand;
use Kraite\Core\Commands\Cronjobs\ComputeMarketRegimeCommand;
use Kraite\Core\Commands\Cronjobs\ConcludeSymbolsDirectionCommand;
use Kraite\Core\Commands\Cronjobs\CreatePositionsCommand;
use Kraite\Core\Commands\Cronjobs\DetectMarketShockCommand;
use Kraite\Core\Commands\Cronjobs\DisableVolatileTokensCommand;
use Kraite\Core\Commands\Cronjobs\FetchKlinesCommand;
use Kraite\Core\Commands\Cronjobs\FlushDispatcherSaturationCommand;
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
use Kraite\Core\Commands\Ingestion\IsEligibleCommand;
use Kraite\Core\Commands\RecoverPositionsCommand;
use Kraite\Core\Commands\SafeToRestartCommand;
use Kraite\Core\Commands\Tests\TestNotificationCommand;
use Kraite\Core\Commands\Tests\ThrottlerStressTestCommand;
use Kraite\Core\Commands\UpdateRecvwindowSafetyDurationCommand;
use Kraite\Core\Commands\WarmupCommand;
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
use Kraite\Core\Support\NotificationService;
use Schema;
use StepDispatcher\Events\StaleStepsDetected;
use Throwable;

final class CoreServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->commands([
            SafeToRestartCommand::class,
            RecoverPositionsCommand::class,
            BackfillOriginalPricesCommand::class,
            UpdateRecvwindowSafetyDurationCommand::class,
            BacktestTokenCommand::class,
            AnalyseBscsCommand::class,
            CheckDriftsCommand::class,
            ComputeMarketRegimeCommand::class,
            DetectMarketShockCommand::class,
            ConcludeSymbolsDirectionCommand::class,
            CreatePositionsCommand::class,
            RenewSubscriptionsCommand::class,
            DisableVolatileTokensCommand::class,
            FetchKlinesCommand::class,
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
        ]);

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'kraite');

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
            \Kraite\Core\Events\UserEmailConfirmed::class,
            [\Kraite\Core\Listeners\AttachPrivateBetaCoupon::class, 'handle'],
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
    }

    public function register(): void
    {
        $this->loadSharedEnvironment();

        $this->mergeConfigFrom(
            __DIR__.'/../config/kraite.php',
            'kraite'
        );

        // Bind the drift-spotter contract to its production implementation.
        // The interface seam exists so tests can swap in canned reports
        // without going through the real exchange API.
        $this->app->bind(
            Support\Drift\DriftChecker::class,
            Support\Drift\DriftCheckService::class,
        );
    }

    protected function loadSharedEnvironment(): void
    {
        $path = env('KRAITE_ENV_PATH', '/home/waygou');
        $file = env('KRAITE_ENV_FILE', '.env.kraite');

        if (file_exists($path.DIRECTORY_SEPARATOR.$file)) {
            Dotenv::createImmutable($path, $file)->safeLoad();
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
}
