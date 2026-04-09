<?php

declare(strict_types=1);

namespace Kraite\Core;

use DateTimeInterface;
use Dotenv\Dotenv;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Kraite\Core\Commands\Cronjobs\CheckStaleDataCommand;
use Kraite\Core\Commands\Cronjobs\ConcludeSymbolsDirectionCommand;
use Kraite\Core\Commands\Cronjobs\CreatePositionsCommand;
use Kraite\Core\Commands\Cronjobs\FetchKlinesCommand;
use Kraite\Core\Commands\Cronjobs\RefreshExchangeSymbolsCommand;
use Kraite\Core\Commands\Cronjobs\StoreAccountsBalancesCommand;
use Kraite\Core\Commands\Cronjobs\SyncOrdersCommand;
use Kraite\Core\Commands\Debug\Accounts\QueryAccountCommand;
use Kraite\Core\Commands\Debug\CalculateOrdersCommand;
use Kraite\Core\Commands\Debug\CancelPositionCommand;
use Kraite\Core\Commands\Debug\ClearTablesCommand;
use Kraite\Core\Commands\Debug\ClosePositionCommand;
use Kraite\Core\Commands\Debug\DumpAccountCredentialsCommand;
use Kraite\Core\Commands\Debug\QueryOrderCommand;
use Kraite\Core\Commands\Debug\QueryPositionsCommand;
use Kraite\Core\Commands\Debug\RetryPositionDispatchCommand;
use Kraite\Core\Commands\Debug\TestApiConnectivityCommand;
use Kraite\Core\Commands\Ingestion\IsEligibleCommand;
use Kraite\Core\Commands\SafeToRestartCommand;
use Kraite\Core\Commands\Tests\TestNotificationCommand;
use Kraite\Core\Commands\UpdateRecvwindowSafetyDurationCommand;
use Kraite\Core\Listeners\NotificationLogListener;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\AccountBalanceHistory;
use Kraite\Core\Models\ApiRequestLog;
use Kraite\Core\Models\ApiSnapshot;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\ForbiddenHostname;
use Kraite\Core\Models\Indicator;
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
use Kraite\Core\Observers\NotificationLogObserver;
use Kraite\Core\Observers\OrderObserver;
use Kraite\Core\Observers\PositionObserver;
use Kraite\Core\Observers\SymbolObserver;
use Kraite\Core\Observers\UserObserver;
use Kraite\Core\Support\NotificationService;
use StepDispatcher\Models\Step;
use StepDispatcher\Observers\StepObserver;

final class CoreServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->commands([
            SafeToRestartCommand::class,
            UpdateRecvwindowSafetyDurationCommand::class,
            CheckStaleDataCommand::class,
            ConcludeSymbolsDirectionCommand::class,
            CreatePositionsCommand::class,
            FetchKlinesCommand::class,
            RefreshExchangeSymbolsCommand::class,
            StoreAccountsBalancesCommand::class,
            SyncOrdersCommand::class,
            QueryAccountCommand::class,
            CalculateOrdersCommand::class,
            CancelPositionCommand::class,
            ClearTablesCommand::class,
            ClosePositionCommand::class,
            DumpAccountCredentialsCommand::class,
            QueryOrderCommand::class,
            QueryPositionsCommand::class,
            RetryPositionDispatchCommand::class,
            TestApiConnectivityCommand::class,
            IsEligibleCommand::class,
            TestNotificationCommand::class,
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

        AccountBalanceHistory::observe(AccountBalanceHistoryObserver::class);
        Account::observe(AccountObserver::class);
        ApiRequestLog::observe(ApiRequestLogObserver::class);
        ApiSnapshot::observe(ApiSnapshotObserver::class);
        ApiSystem::observe(ApiSystemObserver::class);
        Step::observe(StepObserver::class);
        ExchangeSymbol::observe(ExchangeSymbolObserver::class);
        Indicator::observe(IndicatorObserver::class);
        NotificationLog::observe(NotificationLogObserver::class);
        Order::observe(OrderObserver::class);
        Position::observe(PositionObserver::class);
        ForbiddenHostname::observe(ForbiddenHostnameObserver::class);
        Symbol::observe(SymbolObserver::class);
        User::observe(UserObserver::class);

        // Register slow query listener
        $this->registerSlowQueryListener();
    }

    public function register(): void
    {
        $this->loadSharedEnvironment();

        $this->mergeConfigFrom(
            __DIR__.'/../config/kraite.php',
            'kraite'
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
            $threshold = (int) config('kraite.slow_query_threshold_ms', 5000);
            if ($query->time <= $threshold) {
                return;
            }

            if (Str::contains(Str::lower($query->sql), 'slow_queries')) {
                return;
            }

            // Skip during migrations when slow_queries table may not exist yet
            if (! \Schema::hasTable('slow_queries')) {
                return;
            }

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

            // Send notification to admin about slow query
            NotificationService::send(
                user: Kraite::admin(),
                canonical: 'slow_query_detected',
                referenceData: [
                    'sql_full' => $sqlFull,
                    'time_ms' => (int) $query->time,
                    'connection' => $query->connectionName,
                    'threshold_ms' => $threshold,
                ]
            );
        });
    }
}
