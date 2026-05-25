<?php

declare(strict_types=1);

namespace Kraite\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Indicator;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\ModelLog;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Subscription;
use Kraite\Core\Models\Symbol;
use Kraite\Core\Models\TokenMapper;
use Kraite\Core\Models\TradeConfiguration;
use Kraite\Core\Models\User;
use Throwable;

final class KraiteSeeder extends Seeder
{
    /**
     * Seed the application's database with all core data.
     * This consolidated seeder combines all schema seeder logic.
     */
    public function run(): void
    {
        // Disable ModelLog during seeding
        ModelLog::disable();

        // Disable observers during seeding to prevent notification spam
        ExchangeSymbol::withoutEvents(function () {
            Account::withoutEvents(function () {
                Position::withoutEvents(function () {
                    User::withoutEvents(function () {
                        $this->runSeeding();
                    });
                });
            });
        });

        // Re-enable ModelLog after seeding
        ModelLog::enable();
    }

    /**
     * Get all symbol CMC IDs consolidated from all schema seeders.
     * This combines initial symbols, high volatility tokens, and additional batches.
     *
     * NOTE: Symbols are now dynamically discovered from exchanges.
     * This seeder no longer pre-populates symbols.
     */
    public function getAllSymbolCmcIds(): array
    {
        return [];
    }

    /**
     * Seed all indicators.
     */
    public function seedIndicators(): void
    {
        // From SchemaSeeder1
        Indicator::updateOrCreate(
            ['canonical' => 'emas-same-direction'],
            [
                'type' => 'conclude-indicators',
                'is_active' => true,
                'class' => "Kraite\Core\Indicators\RefreshData\EMAsSameDirection",
                'is_computed' => true,
            ]
        );

        Indicator::updateOrCreate(
            ['canonical' => 'candle-comparison'],
            [
                'type' => 'conclude-indicators',
                'is_active' => true,
                'is_computed' => false,
                'class' => "Kraite\Core\Indicators\Ongoing\CandleComparisonIndicator",
                'parameters' => [
                    'results' => 2,
                ],
            ]
        );

        Indicator::updateOrCreate(
            ['canonical' => 'macd'],
            [
                'type' => 'conclude-indicators',
                'is_active' => false,
                'is_computed' => false,
                'class' => "Kraite\Core\Indicators\RefreshData\MACDIndicator",
                'parameters' => [
                    'backtrack' => 1,
                    'results' => 2,
                    'optInFastPeriod' => '12',
                    'optInSlowPeriod' => 26,
                    'optInSignalPeriod' => 9,
                ],
            ]
        );

        // Supertrend - ATR-based trend indicator, cross-exchange proof (OHLC only)
        Indicator::updateOrCreate(
            ['canonical' => 'supertrend'],
            [
                'type' => 'conclude-indicators',
                'is_active' => false,
                'is_computed' => false,
                'class' => "Kraite\Core\Indicators\RefreshData\SupertrendIndicator",
                'parameters' => [
                    'period' => 7,
                    'multiplier' => 3,
                    'results' => 1,
                ],
            ]
        );

        // Stochastic RSI - Combines Stochastic oscillator with RSI, cross-exchange proof (close prices only)
        Indicator::updateOrCreate(
            ['canonical' => 'stochrsi'],
            [
                'type' => 'conclude-indicators',
                'is_active' => false,
                'is_computed' => false,
                'class' => "Kraite\Core\Indicators\RefreshData\StochRSIIndicator",
                'parameters' => [
                    'kPeriod' => 5,
                    'dPeriod' => 3,
                    'rsiPeriod' => 14,
                    'stochasticPeriod' => 14,
                    'results' => 2,
                    'backtrack' => 1,
                ],
            ]
        );

        Indicator::updateOrCreate(
            ['canonical' => 'adx'],
            [
                'type' => 'conclude-indicators',
                'is_active' => false,
                'is_computed' => false,
                'class' => "Kraite\Core\Indicators\RefreshData\ADXIndicator",
                'parameters' => [
                    'results' => 1,
                ],
            ]
        );

        // Choppiness Index — ValidationIndicator that rejects a
        // timeframe as "too choppy to vote on direction". Invalidates
        // BEFORE the unanimous direction gate runs, so the walker moves
        // up a timeframe instead of burning five direction indicators
        // only to end at has_invalid_indicator_direction=1. Period 14
        // is the canonical default; backtrack=1 reads the last CLOSED
        // candle (matches our other direction reads).
        Indicator::updateOrCreate(
            ['canonical' => 'chop'],
            [
                'type' => 'conclude-indicators',
                'is_active' => true,
                'is_computed' => false,
                'class' => "Kraite\Core\Indicators\RefreshData\ChoppinessIndexIndicator",
                'parameters' => [
                    'period' => 14,
                    'results' => 1,
                    'backtrack' => 1,
                ],
            ]
        );

        // Pivot points — ValidationIndicator that always passes. Exists
        // in the pipeline purely so its payload (r3/r2/r1/p/s1/s2/s3)
        // lands in indicator_histories + indicators_values, ready for
        // QueryAndStoreSupportAndResistanceJob to copy into dedicated
        // columns during the direction-finalization phase. Never
        // influences direction conclusion — see the indicator class
        // docblock for rationale.
        Indicator::updateOrCreate(
            ['canonical' => 'pivotpoints'],
            [
                'type' => 'conclude-indicators',
                'is_active' => true,
                'is_computed' => false,
                'class' => "Kraite\Core\Indicators\RefreshData\PivotPointsIndicator",
                'parameters' => [
                    'results' => 1,
                ],
            ]
        );

        Indicator::updateOrCreate(
            ['canonical' => 'emas-convergence'],
            [
                'is_active' => false,
                'class' => "Kraite\Core\Indicators\RefreshData\EMAsConvergence",
                'is_computed' => false,
            ]
        );

        Indicator::updateOrCreate(
            ['canonical' => 'ema-40'],
            [
                'type' => 'conclude-indicators',
                'is_active' => true,
                'is_computed' => false,
                'class' => "Kraite\Core\Indicators\RefreshData\EMAIndicator",
                'parameters' => [
                    'backtrack' => 1,
                    'results' => 2,
                    'period' => '40',
                ],
            ]
        );

        Indicator::updateOrCreate(
            ['canonical' => 'ema-80'],
            [
                'type' => 'conclude-indicators',
                'is_active' => true,
                'is_computed' => false,
                'class' => "Kraite\Core\Indicators\RefreshData\EMAIndicator",
                'parameters' => [
                    'backtrack' => 1,
                    'results' => 2,
                    'period' => '80',
                ],
            ]
        );

        Indicator::updateOrCreate(
            ['canonical' => 'ema-120'],
            [
                'type' => 'conclude-indicators',
                'is_active' => true,
                'is_computed' => false,
                'class' => "Kraite\Core\Indicators\RefreshData\EMAIndicator",
                'parameters' => [
                    'backtrack' => 1,
                    'results' => 2,
                    'period' => '120',
                ],
            ]
        );

        // From SchemaSeeder9 - Update and Create
        Indicator::query()->where('canonical', 'candle-comparison')
            ->update(['class' => 'Kraite\Core\Indicators\RefreshData\CandleComparisonIndicator']);

        Indicator::updateOrCreate(
            ['canonical' => 'candle'],
            [
                'type' => 'history',
                'is_active' => true,
                'is_computed' => true,
                'parameters' => ['results' => 1],
                'class' => "Kraite\Core\Indicators\History\CandleIndicator",
            ]
        );

        // From SchemaSeeder11
        Indicator::updateOrCreate(
            ['canonical' => 'price-volatility'],
            [
                'is_active' => true,
                'type' => 'reports',
                'class' => "Kraite\Core\Indicators\Reports\PriceVolatilityIndicator",
                'is_computed' => true,
                'parameters' => ['results' => 2000],
            ]
        );

        Indicator::where('canonical', 'candle')->where('type', 'history')->first()?->update(['type' => 'dashboard']);

        // AI Trading Desk indicators
        Indicator::updateOrCreate(
            ['canonical' => 'rsi'],
            [
                'type' => 'conclude-indicators',
                'is_active' => false,
                'is_computed' => false,
                'class' => "Kraite\Core\Indicators\RefreshData\RSIIndicator",
                'parameters' => [
                    'period' => 14,
                    'results' => 2,
                    'backtrack' => 1,
                ],
            ]
        );

        Indicator::updateOrCreate(
            ['canonical' => 'bbands'],
            [
                'type' => 'conclude-indicators',
                'is_active' => false,
                'is_computed' => false,
                'class' => "Kraite\Core\Indicators\RefreshData\BollingerBandsIndicator",
                'parameters' => [
                    'period' => 20,
                    'stddev' => 2,
                    'results' => 2,
                    'backtrack' => 1,
                ],
            ]
        );

        Indicator::updateOrCreate(
            ['canonical' => 'atr'],
            [
                'type' => 'conclude-indicators',
                'is_active' => false,
                'is_computed' => false,
                'class' => "Kraite\Core\Indicators\RefreshData\ATRIndicator",
                'parameters' => [
                    'period' => 14,
                    'results' => 1,
                ],
            ]
        );

        Indicator::updateOrCreate(
            ['canonical' => 'obv'],
            [
                'type' => 'conclude-indicators',
                'is_active' => false,
                'is_computed' => false,
                'class' => "Kraite\Core\Indicators\RefreshData\OBVIndicator",
                'parameters' => [
                    'results' => 2,
                    'backtrack' => 1,
                ],
            ]
        );
    }

    /**
     * Seed API systems and return them for reference.
     */
    public function seedApiSystems(): array
    {
        $binance = ApiSystem::updateOrCreate(
            ['canonical' => 'binance'],
            [
                'name' => 'Binance',
                'logo_url' => 'https://public.bnbstatic.com/static/images/common/favicon.ico',
                'is_exchange' => true,
            ]
        );

        $bybit = ApiSystem::updateOrCreate(
            ['canonical' => 'bybit'],
            [
                'name' => 'Bybit',
                'logo_url' => 'https://www.bybit.com/favicon.ico',
                'is_exchange' => true,
            ]
        );

        $kucoin = ApiSystem::updateOrCreate(
            ['canonical' => 'kucoin'],
            [
                'name' => 'KuCoin',
                'logo_url' => 'https://www.kucoin.com/favicon.ico',
                'is_exchange' => true,
            ]
        );

        $bitget = ApiSystem::updateOrCreate(
            ['canonical' => 'bitget'],
            [
                'name' => 'BitGet',
                'logo_url' => 'https://www.bitget.com/favicon.ico',
                'is_exchange' => true,
            ]
        );

        $coinmarketcap = ApiSystem::updateOrCreate(
            ['canonical' => 'coinmarketcap'],
            [
                'name' => 'CoinmarketCap',
                'is_exchange' => false,
            ]
        );

        $alternativeMe = ApiSystem::updateOrCreate(
            ['canonical' => 'alternativeme'],
            [
                'name' => 'AlternativeMe',
                'is_exchange' => false,
            ]
        );

        $taapi = ApiSystem::updateOrCreate(
            ['canonical' => 'taapi'],
            [
                'name' => 'Taapi',
                'is_exchange' => false,
            ]
        );

        return [
            'binance' => $binance,
            'bybit' => $bybit,
            'kucoin' => $kucoin,
            'bitget' => $bitget,
            'coinmarketcap' => $coinmarketcap,
            'alternativeme' => $alternativeMe,
            'taapi' => $taapi,
        ];
    }

    /**
     * Seed the admin user from configuration.
     */
    public function seedAdminUser(): User
    {
        $admin = User::firstOrNew(['email' => config('kraite.admin_user_email')]);

        if (! $admin->exists && empty($admin->uuid)) {
            $admin->uuid = (string) Str::uuid();
        }

        $admin->fill([
            'name' => config('kraite.admin_user_name'),
            'password' => bcrypt(config('kraite.admin_user_password', 'password')),
            'status' => 'active',
            'is_active' => true,
            'is_admin' => true,
            // Wire notification routing so user-scoped pushovers
            // (position_opened / position_closed / position_wap_applied
            // / position_high_profit_closed) actually deliver.
            // Without these, AlertNotification::via() returns [] and
            // every trading-event push silently drops.
            'pushover_key' => config('kraite.admin_user_pushover_key')
                ?? config('kraite.admin_user_pushover_user_key'),
            'notification_channels' => ['pushover', 'mail'],
        ]);

        $admin->save();

        return $admin;
    }

    /**
     * Seed the default trade configuration.
     */
    public function seedTradeConfiguration(): void
    {
        TradeConfiguration::updateOrCreate(
            ['canonical' => 'standard'],
            [
                'is_default' => true,
                'description' => 'Standard trade configuration, default for all tokens',
            ]
        );
    }

    /**
     * Seed initial symbols from SchemaSeeder1 and SchemaSeeder2.
     */
    public function seedSymbols(): void
    {
        $allSymbolCmcIds = $this->getAllSymbolCmcIds();

        $rows = [];
        $now = now();

        foreach ($allSymbolCmcIds as $cmcId) {
            $rows[] = [
                'cmc_id' => $cmcId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        Symbol::insert($rows);
    }

    /**
     * Seed steps dispatchers.
     */
    public function seedStepsDispatchers(): void
    {
        // From StepsDispatcherSeeder - 10 group-based records
        $groups = [
            'alpha', 'beta', 'gamma', 'delta', 'epsilon',
            'zeta', 'eta', 'theta', 'iota', 'kappa',
        ];

        $now = now();

        // Avoid duplicates
        $existing = DB::table('steps_dispatcher')
            ->whereIn('group', $groups)
            ->pluck('group')
            ->all();

        $missing = array_values(array_diff($groups, $existing));

        if (! empty($missing)) {
            $rows = array_map(static function (string $g) use ($now) {
                return [
                    'group' => $g,
                    'can_dispatch' => true,
                    'current_tick_id' => null,
                    'last_tick_completed' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }, $missing);

            DB::table('steps_dispatcher')->insert($rows);
        }
    }

    /**
     * Update trade configuration with hedge quantity laddering.
     */
    public function updateTradeConfiguration(): void
    {
        TradeConfiguration::query()->update([
            'hedge_quantity_laddering_percentages' => [110, 75, 40, 20],
        ]);
    }

    /**
     * Seed the Engine singleton record.
     */
    public function seedKraite(): void
    {
        Kraite::updateOrCreate(
            ['id' => 1],
            [
                'allow_opening_positions' => true,
                'is_cooling_down' => false,
                'admin_pushover_application_key' => config('kraite.admin_user_pushover_application_key'),
                'admin_pushover_user_key' => config('kraite.admin_user_pushover_user_key'),
                'admin_telegram_chat_id' => config('kraite.admin_user_telegram_chat_id'),
                'email' => config('kraite.admin_user_email'),
                'timeframes' => ['1h', '4h', '12h', '1d'],
            ]
        );
    }

    /**
     * Seed additional symbol batches (legacy method maintained for backwards compatibility).
     * All symbols are now seeded via seedSymbols().
     */
    public function seedAdditionalSymbols(): void {}

    /**
     * Update exchange symbols settings.
     */
    public function updateExchangeSymbols(): void
    {
        // From SchemaSeeder12 - Set limit quantity multipliers and gap percentages
        ExchangeSymbol::query()->update([
            'limit_quantity_multipliers' => [2, 2, 2, 2],
            'percentage_gap_long' => 8.5,
            'percentage_gap_short' => 9.5,
        ]);
    }

    /**
     * Migrate Kraite credentials from configuration to database.
     */
    public function migrateKraiteCredentials(): void
    {
        $engine = Kraite::find(1);

        if ($engine) {
            $engine->binance_api_key = config('kraite.api.credentials.binance.api_key');
            $engine->binance_api_secret = config('kraite.api.credentials.binance.api_secret');
            $engine->kucoin_api_key = config('kraite.api.credentials.kucoin.api_key');
            $engine->kucoin_api_secret = config('kraite.api.credentials.kucoin.api_secret');
            $engine->kucoin_passphrase = config('kraite.api.credentials.kucoin.passphrase');
            $engine->bitget_api_key = config('kraite.api.credentials.bitget.api_key');
            $engine->bitget_api_secret = config('kraite.api.credentials.bitget.api_secret');
            $engine->bitget_passphrase = config('kraite.api.credentials.bitget.passphrase');
            $engine->coinmarketcap_api_key = config('kraite.api.credentials.coinmarketcap.api_key');
            $engine->taapi_secret = config('kraite.api.credentials.taapi.secret');
            $engine->nowpayments_api_key = config('kraite.api.credentials.nowpayments.api_key');
            $engine->nowpayments_ipn_secret = config('kraite.api.credentials.nowpayments.ipn_secret');

            $resendApiKey = config('services.resend.key');

            if (filled($resendApiKey)) {
                $engine->resend_api_key = $resendApiKey;
            }

            $engine->save();
        }
    }

    /**
     * Add notification channels to Kraite.
     */
    public function addNotificationChannels(): void
    {
        $engine = Kraite::find(1);

        if ($engine) {
            $engine->bybit_api_key = config('kraite.api.credentials.bybit.api_key');
            $engine->bybit_api_secret = config('kraite.api.credentials.bybit.api_secret');
            $engine->notification_channels = ['pushover', 'mail'];
            $engine->save();
        }
    }

    /**
     * Move admin Pushover key from config to database.
     */
    public function moveAdminPushoverKey(): void
    {
        $engine = Kraite::find(1);

        if ($engine) {
            $adminPushoverKey = config('kraite.admin_user_pushover_key');

            if ($adminPushoverKey) {
                $engine->admin_pushover_user_key = $adminPushoverKey;
                $engine->save();
            }
        }
    }

    /**
     * Seed the API execution servers.
     *
     * Local installs register the current machine so `Kraite::ip()` resolves
     * to the developer box. Production registers only the ingestion/workers
     * that can perform exchange API requests and need user whitelisting.
     */
    public function seedServers(): void
    {
        $servers = app()->environment('production')
            ? $this->productionApiServers()
            : $this->localApiServer();

        foreach ($servers as $server) {
            DB::table('servers')->updateOrInsert(
                ['hostname' => $server['hostname']],
                array_merge($server, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }
    }

    /**
     * Seed common notification definitions.
     */
    public function seedNotifications(): void
    {
        $notifications = [
            [
                'canonical' => 'server_rate_limit_exceeded',
                'title' => 'Server Rate Limit Exceeded',
                'description' => 'Sent when server hits API rate limit',
                'usage_reference' => 'ApiRequestLogObserver',
                'default_severity' => 'high',
                'verified' => 1,
                'cache_duration' => 600,
                'cache_key' => ['api_system', 'account', 'server'],
            ],
            [
                'canonical' => 'server_ip_forbidden',
                'title' => 'Server IP Forbidden by Exchange',
                'description' => 'Sent when server/IP is forbidden from accessing exchange API (HTTP 418 IP ban)',
                'usage_reference' => 'ApiRequestLogObserver',
                'default_severity' => 'critical',
                'verified' => 1,
                'cache_duration' => 60,
                'cache_key' => ['api_system', 'server'],
            ],
            [
                'canonical' => 'exchange_symbol_no_taapi_data',
                'title' => 'Exchange Symbol Auto-Deactivated - No TAAPI Data',
                'description' => 'Sent when an exchange symbol is automatically deactivated due to consistent lack of TAAPI indicator data',
                'usage_reference' => 'ApiRequestLogObserver',
                'default_severity' => 'info',
                'verified' => 1,
                'cache_duration' => 600,
                'cache_key' => ['exchange_symbol', 'exchange'],
            ],
            [
                'canonical' => 'token_delisting',
                'title' => 'Token Delisting Detected',
                'description' => 'Sent when a token delisting is detected (contract rollover for Binance, perpetual delisting for Bybit)',
                'usage_reference' => 'ExchangeSymbol/SendsNotifications',
                'default_severity' => 'high',
                'verified' => 1,
                'cache_duration' => 600,
                'cache_key' => ['exchange_symbol'],
            ],
            // Slow query detection
            [
                'canonical' => 'slow_query_detected',
                'title' => 'Slow Database Query Detected',
                'description' => 'Triggered when a database query exceeds the configured slow_query_threshold_ms value (default: 2500ms)',
                'detailed_description' => 'This notification is sent when a database query takes longer than the threshold configured in config/kraite.php. '.
                    'The notification includes the full SQL query with binded values (ready to copy-paste into SQL editor), execution time, and connection name. '.
                    'Slow queries can indicate performance issues, missing indexes, or inefficient queries that need optimization.',
                'usage_reference' => 'Used in CoreServiceProvider::registerSlowQueryListener() - triggered automatically when DB::listen() detects slow queries',
                'default_severity' => 'high',
                'verified' => 1,
                'cache_duration' => 300,
                'cache_key' => ['connection'],
            ],
            // Stale dispatched steps (with self-healing)
            [
                'canonical' => 'stale_dispatched_steps_detected',
                'title' => 'Stale Dispatched Steps Detected',
                'description' => 'Triggered when steps remain in Dispatched state for more than 5 minutes without starting processing',
                'detailed_description' => 'This notification is sent when steps are stuck in Dispatched state for over 5 minutes. '.
                    'Steps in Dispatched state should normally transition to Running state within seconds. '.
                    'The system auto-promotes stale steps to priority queue for faster processing. '.
                    'If steps remain stuck after promotion, a CRITICAL notification (stale_priority_steps_detected) will be sent.',
                'usage_reference' => 'CheckStaleDataCommand',
                'default_severity' => 'high',
                'verified' => 1,
                'cache_duration' => 60,
                'cache_key' => null,
            ],
            // Group-progress watchdog (catches cleanup-phase wedges that the
            // per-step detector misses — see ~/steps-dispatcher/issue.md and
            // the 2026-04-25 incident write-up)
            [
                'canonical' => 'group_no_progress_detected',
                'title' => 'Dispatcher Group Stalled - No Terminal Progress',
                'description' => 'Triggered when a dispatcher group has Pending steps but no terminal-state step has been updated within the watchdog threshold (default 10 minutes)',
                'detailed_description' => 'This CRITICAL notification is sent when a dispatcher group has accumulated Pending work but is no longer concluding any step in a terminal state. '.
                    'Generalised stall detection that catches cleanup-phase wedges the per-step detector misses — '.
                    'no individual step is stuck, but no group-level work is making progress either. '.
                    'The 2026-04-25 incident hid this exact failure mode for 16h before manual intervention; this alarm closes that blind spot. '.
                    'Inspect the affected group via the admin /system/step-dispatcher view and check for Skipped parents with non-null child_block_uuid (the historical wedge shape).',
                'usage_reference' => 'RecoverStaleStepsCommand --watchdog-progress',
                'default_severity' => 'critical',
                'verified' => 1,
                'cache_duration' => 600,
                'cache_key' => ['group'],
            ],
            // Stale priority steps (critical - self-healing failed)
            [
                'canonical' => 'stale_priority_steps_detected',
                'title' => 'Priority Steps Still Stuck - Manual Action Required',
                'description' => 'Triggered when steps remain stuck in Dispatched state even after being promoted to the priority queue',
                'detailed_description' => 'This CRITICAL notification is sent when steps are still stuck in Dispatched state after '.
                    'being automatically promoted to the priority queue with high priority. '.
                    'This means the self-healing mechanism has FAILED and manual intervention is required. '.
                    'Possible causes include: Horizon priority workers not running, Redis connection issues, '.
                    'queue driver misconfiguration, or worker memory exhaustion.',
                'usage_reference' => 'CheckStaleDataCommand',
                'default_severity' => 'critical',
                'verified' => 1,
                'cache_duration' => 60,
                'cache_key' => null,
            ],
            // Forbidden hostname notifications
            [
                'canonical' => 'server_ip_not_whitelisted',
                'title' => 'Server IP Not Whitelisted',
                'description' => 'Your API key requires the server IP to be whitelisted. Please add the IP address to your exchange API key settings.',
                'detailed_description' => 'This notification is sent when the exchange API rejects requests because the server IP address is not in your API key\'s whitelist. '.
                    'To fix this, log into your exchange account, go to API settings, and add the IP address shown in this notification to your API key\'s allowed IP list.',
                'usage_reference' => 'ForbiddenHostnameObserver',
                'default_severity' => 'high',
                'verified' => 1,
                'cache_duration' => 3600,
                'cache_key' => ['account_id', 'ip_address'],
            ],
            [
                'canonical' => 'server_ip_rate_limited',
                'title' => 'Server IP Rate Limited',
                'description' => 'The server IP has been temporarily rate-limited by the exchange. Requests will automatically resume after the ban expires.',
                'detailed_description' => 'This notification is sent when the exchange temporarily blocks the server IP due to excessive requests. '.
                    'This is typically an automatic protection that expires after a few minutes. The system will automatically resume operations once the ban lifts.',
                'usage_reference' => 'ForbiddenHostnameObserver',
                'default_severity' => 'high',
                'verified' => 1,
                'cache_duration' => 300,
                'cache_key' => ['api_system', 'ip_address'],
            ],
            [
                'canonical' => 'server_ip_banned',
                'title' => 'Server IP Permanently Banned',
                'description' => 'The server IP has been permanently banned by the exchange. Manual intervention required.',
                'detailed_description' => 'This notification is sent when the exchange permanently bans the server IP address. '.
                    'This typically occurs after repeated violations of rate limits or terms of service. '.
                    'To resolve this, you may need to contact the exchange support team directly.',
                'usage_reference' => 'ForbiddenHostnameObserver',
                'default_severity' => 'critical',
                'verified' => 1,
                'cache_duration' => 3600,
                'cache_key' => ['api_system', 'ip_address'],
            ],
            [
                'canonical' => 'server_account_blocked',
                'title' => 'Account API Access Blocked',
                'description' => 'Your exchange account API access has been blocked. Please check your API key settings or regenerate your API key.',
                'detailed_description' => 'This notification is sent when the exchange rejects your API key. '.
                    'Common causes include: API key revoked, API key disabled, insufficient permissions, payment required, or account restrictions. '.
                    'To fix this, log into your exchange account, check your API key status, and if needed, generate a new API key with the correct permissions.',
                'usage_reference' => 'ForbiddenHostnameObserver',
                'default_severity' => 'critical',
                'verified' => 1,
                'cache_duration' => 3600,
                'cache_key' => ['account_id', 'api_system'],
            ],
            [
                'canonical' => 'account_all_workers_blacklisted',
                'title' => 'URGENT — Account Deactivated, Portfolio Unmanaged',
                'description' => 'Every worker server IP is blacklisted on the exchange for your account. The system has deactivated the account because it cannot communicate with the exchange to manage your open positions.',
                'detailed_description' => 'This notification fires when the worker-IP rotation engine has exhausted every apiable worker for an account — every fleet IP is either not whitelisted on your API key or has been blocked by the exchange. The system can no longer place, modify, or close orders on this account, so it has been disabled to stop further failed dispatches. '.
                    'YOUR OPEN POSITIONS REMAIN ON THE EXCHANGE BUT ARE NOT BEING MANAGED. '.
                    'To recover: (1) verify your API key is valid and has the correct permissions, (2) add every Kraite worker IP listed in the email to your API key whitelist, (3) re-activate the account in the admin panel. The first cron tick after re-activation will verify connectivity end-to-end.',
                'usage_reference' => 'BaseApiableJob::deactivateAccountAndFail',
                'default_severity' => 'critical',
                'verified' => 1,
                'cache_duration' => 3600,
                'cache_key' => ['account_id', 'api_system'],
            ],
            // Trading-lifecycle notifications — emitted from the position
            // workflow at key state transitions. Most ship at Pushover
            // priority -1 (low / silent) so day-to-day flow doesn't
            // interrupt the device. WAP specifically ships at priority 1
            // (high) because it's the signal that DCA rungs are filling
            // and the strategy's exit is being repositioned based on the
            // exchange's breakEvenPrice.
            [
                'canonical' => 'position_opened',
                'title' => 'Position Opened',
                'description' => 'Emitted when a new position transitions to active — market entry filled, TP/SL/LIMITs placed.',
                'usage_reference' => 'Jobs/Atomic/Position/ActivatePositionJob',
                'default_severity' => 'info',
                'verified' => 1,
                'cache_duration' => 60,
                'cache_key' => ['position'],
                // Muted on Bruno's call — too chatty on a 12-slot
                // book. Matches the commented-out
                // dispatchOpenedNotification() call in
                // ActivatePositionJob::complete(). Re-enable both
                // (here + the call site) when adding a
                // digest / quiet-hours filter.
                'is_active' => false,
            ],
            [
                'canonical' => 'position_closed',
                'title' => 'Position Closed',
                'description' => 'Emitted when a position transitions to closed — via organic TP/SL fill, manual close, or workflow close.',
                'usage_reference' => 'Jobs/Atomic/Position/UpdateRemainingClosingDataJob',
                'default_severity' => 'info',
                'verified' => 1,
                'cache_duration' => 60,
                'cache_key' => ['position'],
            ],
            [
                'canonical' => 'position_wap_applied',
                'title' => 'Position WAP Applied',
                'description' => 'Emitted when the weighted-average-price workflow successfully modifies the profit order after one or more DCA fills.',
                'detailed_description' => 'Delivered at Pushover priority 1 (high) so it bypasses the device\'s quiet hours — a WAP is a meaningful strategy signal that DCA rungs are filling and the exit is being repositioned based on the exchange\'s breakEvenPrice.',
                'usage_reference' => 'Jobs/Atomic/Order/CalculateWapAndModifyProfitOrderJob',
                'default_severity' => 'high',
                'verified' => 1,
                'cache_duration' => 30,
                'cache_key' => ['position'],
            ],
            [
                'canonical' => 'position_high_profit_closed',
                'title' => 'High-Profit Position Closed',
                'description' => 'Celebratory ping when a position closes after filling >= total_limit_orders_filled_to_notify (full ladder took).',
                'usage_reference' => 'Jobs/Atomic/Position/UpdateRemainingClosingDataJob',
                'default_severity' => 'info',
                'verified' => 1,
                'cache_duration' => 60,
                'cache_key' => ['position'],
            ],
            [
                'canonical' => 'position_pump_cooldown_triggered',
                'title' => 'Pump Cooldown Triggered',
                'description' => 'Emitted when a position close detects a price spike against the exchange_symbol\'s disable_on_price_spike_percentage threshold and sets a tradeable_at cooldown on the symbol.',
                'detailed_description' => 'The symbol is prevented from being retradeable for price_spike_cooldown_hours. The system already reacted — this ping exists so the operator can investigate whether the spike was a news-driven move (keep cooled) vs a noise spike (manually re-enable).',
                'usage_reference' => 'Jobs/Atomic/Position/ClosePositionAtomicallyJob',
                'default_severity' => 'high',
                'verified' => 1,
                'cache_duration' => 600,
                'cache_key' => ['exchange_symbol'],
            ],
            [
                'canonical' => 'position_opening_failed',
                'title' => 'Position Opening Failed',
                'description' => 'Emitted when a create-position workflow fails and the position transitions to status=failed. Also auto-disables the symbol (is_manually_enabled=false) so the scheduler stops picking it.',
                'detailed_description' => 'Any exception that escapes the opening step chain (market place, limit ladder, TP/SL placement) cascades into CancelPositionJob and ultimately sets the position to failed. This ping flags the event so the operator can investigate the root cause, manually reconcile any partial exchange state, and re-enable the token after the issue is understood.',
                'usage_reference' => 'Concerns/Position/HasStatuses::updateToFailed',
                'default_severity' => 'high',
                'verified' => 1,
                'cache_duration' => 60,
                'cache_key' => ['position'],
            ],
            [
                'canonical' => 'position_residual_detected',
                'title' => 'Residual Position Detected After Close',
                'description' => 'Emitted when VerifyPositionResidualAmountJob finds a non-zero positionAmt on the exchange AFTER a close workflow completed. Manual reconciliation required.',
                'detailed_description' => 'Close-on-exchange reported success but the exchange still carries a position. Possible causes: partial fill race, reduceOnly rejection, or exchange-side ledger lag. Inspect the position on the exchange UI and reconcile manually.',
                'usage_reference' => 'Jobs/Atomic/Position/VerifyPositionResidualAmountJob',
                'default_severity' => 'critical',
                'verified' => 1,
                'cache_duration' => 60,
                'cache_key' => ['position'],
            ],
        ];

        foreach ($notifications as $notification) {
            DB::table('notifications')->updateOrInsert(
                ['canonical' => $notification['canonical']],
                [
                    'title' => $notification['title'],
                    'description' => $notification['description'],
                    'detailed_description' => $notification['detailed_description'] ?? $notification['description'],
                    'usage_reference' => $notification['usage_reference'] ?? null,
                    'default_severity' => $notification['default_severity'],
                    'verified' => $notification['verified'] ?? 0,
                    'cache_duration' => $notification['cache_duration'] ?? null,
                    'cache_key' => isset($notification['cache_key']) ? json_encode($notification['cache_key']) : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }

    /**
     * Seed subscription plans.
     */
    public function seedSubscriptions(): void
    {
        // Billing columns (daily_rate_usdt, trial_days) are seeded by a
        // later migration — omitted here so the create-subscriptions
        // migration's seed call works before those columns exist.
        Subscription::updateOrCreate(
            ['canonical' => 'starter'],
            [
                'name' => 'Starter',
                'description' => 'Entry-level plan with 1 account, 1 exchange, and up to 10K balance.',
                'max_accounts' => 1,
                'max_exchanges' => 1,
                'max_balance' => 10000.00,
                'is_active' => true,
            ]
        );

        Subscription::updateOrCreate(
            ['canonical' => 'unlimited'],
            [
                'name' => 'Unlimited',
                'description' => 'Full access with unlimited accounts, exchanges, and no balance cap.',
                'max_accounts' => null,
                'max_exchanges' => null,
                'max_balance' => null,
                'is_active' => true,
            ]
        );
    }

    /**
     * Seed token mappers for exchanges that use different token naming conventions than Binance.
     * Binance token names are the reference since TAAPI indicators use Binance data.
     *
     * Only seeding for exchanges with active API keys: KuCoin and Bybit.
     * BitGet mappings can be added later when their API keys are configured.
     */
    public function seedTokenMappers(array $apiSystems): void
    {
        $mappings = [
            // KuCoin mappings (api_system_id from $apiSystems['kucoin'])
            // Pattern: 1000X -> 10000X
            ['binance_token' => '1000CAT', 'other_token' => '10000CAT', 'exchange' => 'kucoin'],
            ['binance_token' => '1000SATS', 'other_token' => '10000SATS', 'exchange' => 'kucoin'],
            // Pattern: 1000X -> X (remove prefix)
            ['binance_token' => '1000FLOKI', 'other_token' => 'FLOKI', 'exchange' => 'kucoin'],
            ['binance_token' => '1000LUNC', 'other_token' => 'LUNC', 'exchange' => 'kucoin'],
            ['binance_token' => '1000PEPE', 'other_token' => 'PEPE', 'exchange' => 'kucoin'],
            ['binance_token' => '1000SHIB', 'other_token' => 'SHIB', 'exchange' => 'kucoin'],
            ['binance_token' => '1000XEC', 'other_token' => 'XEC', 'exchange' => 'kucoin'],
            // Pattern: BTC -> XBT (KuCoin uses XBT for Bitcoin)
            ['binance_token' => 'BTC', 'other_token' => 'XBT', 'exchange' => 'kucoin'],

            // Bybit mappings (api_system_id from $apiSystems['bybit'])
            // Pattern: 1000X -> 10000X
            ['binance_token' => '1000SATS', 'other_token' => '10000SATS', 'exchange' => 'bybit'],
        ];

        foreach ($mappings as $mapping) {
            $apiSystem = $apiSystems[$mapping['exchange']] ?? null;

            if (! $apiSystem) {
                continue;
            }

            TokenMapper::updateOrCreate(
                [
                    'binance_token' => $mapping['binance_token'],
                    'other_api_system_id' => $apiSystem->id,
                ],
                [
                    'other_token' => $mapping['other_token'],
                ]
            );
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function localApiServer(): array
    {
        $hostname = gethostname() ?: 'full-stack';
        $queueName = mb_strtolower(str_replace('-', '', $hostname));

        return [[
            'hostname' => $hostname,
            'ip_address' => app()->environment('testing') ? '127.0.0.1' : Kraite::ip(),
            'is_apiable' => true,
            'needs_whitelisting' => true,
            'own_queue_name' => $queueName,
            'description' => 'Local full-stack server - all services',
            'type' => 'ingestion',
            'secret' => config('kraite.server_secrets.ingestion'),
        ]];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function productionApiServers(): array
    {
        $path = base_path('../.credentials/kraite/servers.json');

        if (! File::exists($path)) {
            return $this->localApiServer();
        }

        $raw = json_decode((string) File::get($path), associative: true);

        if (! is_array($raw)) {
            return $this->localApiServer();
        }

        return collect($raw)
            ->only(['ingestion', 'worker-1', 'worker-2'])
            ->map(static function (array $server, string $key): array {
                $hostname = (string) ($server['hostname'] ?? $key);

                return [
                    'hostname' => $hostname,
                    'ip_address' => (string) ($server['host'] ?? ''),
                    'is_apiable' => true,
                    'needs_whitelisting' => true,
                    'own_queue_name' => $hostname,
                    'description' => (string) ($server['name'] ?? $server['role'] ?? $hostname),
                    'type' => $key === 'ingestion' ? 'ingestion' : 'worker',
                    'secret' => config('kraite.server_secrets.'.$hostname),
                ];
            })
            ->filter(static fn (array $server): bool => $server['ip_address'] !== '')
            ->values()
            ->all();
    }

    /**
     * Run all seeding operations with observers disabled.
     */
    private function runSeeding(): void
    {
        $this->seedIndicators();
        $apiSystems = $this->seedApiSystems();
        $this->seedAdminUser();
        $this->seedTradeConfiguration();
        $this->seedSymbols();
        $this->seedStepsDispatchers();
        $this->seedKraite();
        $this->seedAdditionalSymbols();
        $this->updateExchangeSymbols();
        $this->migrateKraiteCredentials();
        $this->addNotificationChannels();
        $this->moveAdminPushoverKey();
        $this->seedServers();
        $this->seedNotifications();
        $this->seedTokenMappers($apiSystems);
        $this->seedCoreSymbolData();
        $this->seedSubscriptions();
    }

    /**
     * Seed symbols table from SQL dump.
     * Exchange symbols are populated separately via kraite:cron-refresh-exchange-symbols.
     * If dump doesn't exist or is incompatible, seeding is skipped.
     */
    private function seedCoreSymbolData(): void
    {
        $dumpsPath = __DIR__.'/../dumps';
        $symbolsDump = $dumpsPath.'/symbols.sql';

        // Check if dump file exists
        if (! File::exists($symbolsDump)) {
            // Dump doesn't exist - skip seeding
            return;
        }

        try {
            // Disable foreign key checks for truncation
            DB::statement('SET FOREIGN_KEY_CHECKS=0');

            // Truncate symbols table before loading dump
            DB::table('symbols')->truncate();

            // Execute dump file
            $sql = File::get($symbolsDump);

            // Remove mysqldump warnings from SQL
            $sql = preg_replace('/^mysqldump:.*$/m', '', $sql);

            // Execute SQL using unprepared statements (faster for bulk inserts)
            DB::unprepared($sql);

            // Delete symbols without cmc_id (orphaned records from old workflows)
            DB::table('symbols')->whereNull('cmc_id')->delete();

            // Re-enable foreign key checks
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        } catch (Throwable $e) {
            // Dump file is incompatible with current schema - skip seeding
            return;
        }
    }
}
