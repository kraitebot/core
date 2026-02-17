<?php

declare(strict_types=1);

namespace Kraite\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Indicator;
use Kraite\Core\Models\Engine;
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
        \Kraite\Core\Models\ModelLog::disable();

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
        \Kraite\Core\Models\ModelLog::enable();
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
                'is_active' => true,
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

        // OBV removed from active use - volume-based indicators are not cross-exchange proof
        // Class file retained at RefreshData/OBVIndicator.php for future use

        // Supertrend - ATR-based trend indicator, cross-exchange proof (OHLC only)
        Indicator::updateOrCreate(
            ['canonical' => 'supertrend'],
            [
                'type' => 'conclude-indicators',
                'is_active' => true,
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
                'is_active' => true,
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
                'is_active' => true,
                'is_computed' => false,
                'class' => "Kraite\Core\Indicators\RefreshData\ADXIndicator",
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
    }

    /**
     * Seed API systems and return them for reference.
     */
    public function seedApiSystems(): array
    {
        $binance = ApiSystem::firstOrCreate(
            ['canonical' => 'binance'],
            [
                'name' => 'Binance',
                'logo_url' => 'https://public.bnbstatic.com/static/images/common/favicon.ico',
                'is_exchange' => true,
                'timeframes' => ['5m', '1h', '4h', '12h', '1d'],
            ]
        );

        $bybit = ApiSystem::firstOrCreate(
            ['canonical' => 'bybit'],
            [
                'name' => 'Bybit',
                'logo_url' => 'https://www.bybit.com/favicon.ico',
                'is_exchange' => true,
                'timeframes' => ['5m', '1h', '4h', '12h', '1d'],
            ]
        );

        $kucoin = ApiSystem::firstOrCreate(
            ['canonical' => 'kucoin'],
            [
                'name' => 'KuCoin',
                'logo_url' => 'https://www.kucoin.com/favicon.ico',
                'is_exchange' => true,
                'timeframes' => ['5m', '1h', '4h', '12h', '1d'],
            ]
        );

        $bitget = ApiSystem::firstOrCreate(
            ['canonical' => 'bitget'],
            [
                'name' => 'BitGet',
                'logo_url' => 'https://www.bitget.com/favicon.ico',
                'is_exchange' => true,
                'timeframes' => ['5m', '1h', '4h', '12h', '1d'],
            ]
        );

        $coinmarketcap = ApiSystem::firstOrCreate(
            ['canonical' => 'coinmarketcap'],
            [
                'name' => 'CoinmarketCap',
                'is_exchange' => false,
            ]
        );

        $alternativeMe = ApiSystem::firstOrCreate(
            ['canonical' => 'alternativeme'],
            [
                'name' => 'AlternativeMe',
                'is_exchange' => false,
            ]
        );

        $taapi = ApiSystem::firstOrCreate(
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
     * Seed the Binance+Bybit user/trader.
     */
    public function seedUser(): User
    {
        $userData = [
            'name' => env('TRADER_BB_NAME'),
            'email' => env('TRADER_BB_EMAIL'),
            'password' => bcrypt(env('TRADER_BB_PASSWORD', 'password')),
            'is_active' => true,
            'is_admin' => true,
            'pushover_key' => env('TRADER_BB_PUSHOVER_KEY'),
            'notification_channels' => ['mail', 'pushover'],
        ];

        return User::updateOrCreate(
            ['email' => $userData['email']],
            $userData
        );
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
     * Seed the Binance account for the Binance+Bybit trader.
     */
    public function seedBinanceAccount(User $trader, ApiSystem $binance): void
    {
        Account::updateOrCreate(
            [
                'user_id' => $trader->id,
                'api_system_id' => $binance->id,
            ],
            [
                'uuid' => (string) Str::uuid(),
                'name' => 'Main Binance Account',
                'portfolio_quote' => 'USDT',
                'trading_quote' => 'USDT',
                'trade_configuration_id' => 1,
                'binance_api_key' => env('TRADER_BB_BINANCE_API_KEY'),
                'binance_api_secret' => env('TRADER_BB_BINANCE_API_SECRET'),
                'market_order_margin_percentage_long' => '5.00',
                'market_order_margin_percentage_short' => '5.00',
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
        Engine::updateOrCreate(
            ['id' => 1],
            [
                'allow_opening_positions' => true,
                'admin_pushover_application_key' => env('ADMIN_USER_PUSHOVER_APPLICATION_KEY'),
                'admin_pushover_user_key' => env('ADMIN_USER_PUSHOVER_USER_KEY'),
                'email' => env('ADMIN_USER_EMAIL'),
            ]
        );
    }

    /**
     * Seed additional symbol batches from various schema seeders.
     * This method is now empty as all symbols are seeded in seedSymbols().
     */
    public function seedAdditionalSymbols(): void
    {
        // All symbols are now seeded in seedSymbols() using getAllSymbolCmcIds()
    }

    /**
     * Update specific positions with hardcoded profit prices.
     */
    public function updatePositionProfitPrices(): void
    {
        $positionData = [
            1072 => 3.04389988, // TONUSDT
            1073 => 0.00318842, // DEGENUSDT
            1064 => 321.68888500, // AAVEUSDT
            1063 => 0.43392483, // ARKUSDT
            1060 => 0.30980976, // SUSDT
            1061 => 0.20292457, // PNUTUSDT
            989 => 566.23485550, // BCHUSDT
            983 => 0.95447097, // ONDUSDT
            974 => 0.26890577, // SANDUSDT
            973 => 0.82540880, // ALGUSDT
            782 => 124.80333000, // LTCUSDT
            733 => 887.30647000, // BNBUSDT
        ];

        foreach ($positionData as $id => $profitPrice) {
            $position = Position::find($id);

            if ($position) {
                $position->updateSaving([
                    'first_profit_price' => $profitPrice,
                ]);
            }
        }
    }

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
     * Migrate exchange credentials from JSON to dedicated columns.
     */
    public function migrateAccountCredentials(): void
    {
        $account = Account::find(1);

        if ($account && isset($account->credentials['api_key'])) {
            $account->binance_api_key = $account->credentials['api_key'];
            $account->binance_api_secret = $account->credentials['api_secret'];
            $account->save();
        }
    }

    /**
     * Migrate Kraite credentials from environment to database.
     */
    public function migrateKraiteCredentials(): void
    {
        $engine = Engine::find(1);

        if ($engine) {
            $engine->binance_api_key = env('BINANCE_API_KEY');
            $engine->binance_api_secret = env('BINANCE_API_SECRET');
            $engine->kucoin_api_key = env('KUCOIN_API_KEY');
            $engine->kucoin_api_secret = env('KUCOIN_API_SECRET');
            $engine->kucoin_passphrase = env('KUCOIN_PASSPHRASE');
            $engine->bitget_api_key = env('BITGET_API_KEY');
            $engine->bitget_api_secret = env('BITGET_API_SECRET');
            $engine->bitget_passphrase = env('BITGET_PASSPHRASE');
            $engine->coinmarketcap_api_key = env('COINMARKETCAP_API_KEY');
            $engine->taapi_secret = env('TAAPI_SECRET');
            $engine->save();
        }
    }

    /**
     * Setup Bybit integration: API system and account.
     */
    public function setupBybitIntegration(User $trader, ApiSystem $bybitApiSystem): void
    {
        // Create Bybit account for Binance+Bybit trader
        $existingBybitAccount = Account::where('user_id', $trader->id)
            ->where('api_system_id', $bybitApiSystem->id)
            ->first();

        if (! $existingBybitAccount) {
            Account::create([
                'uuid' => (string) Str::uuid(),
                'name' => 'Main Bybit Account',
                'user_id' => $trader->id,
                'api_system_id' => $bybitApiSystem->id,
                'portfolio_quote' => 'USDT',
                'trading_quote' => 'USDT',
                'trade_configuration_id' => 1,
                'bybit_api_key' => env('TRADER_BB_BYBIT_API_KEY'),
                'bybit_api_secret' => env('TRADER_BB_BYBIT_API_SECRET'),
                'market_order_margin_percentage_long' => '5.00',
                'market_order_margin_percentage_short' => '5.00',
            ]);
        }
    }

    /**
     * Setup Binance-only integration: Create Binance-only user and account.
     */
    public function setupBinanceOnlyIntegration(ApiSystem $binanceApiSystem): void
    {
        // Create Binance-only user
        $binanceEmail = env('TRADER_B_EMAIL');

        if (! $binanceEmail) {
            return;
        }

        $binanceUser = User::updateOrCreate(
            ['email' => $binanceEmail],
            [
                'name' => env('TRADER_B_NAME'),
                'password' => bcrypt(env('TRADER_B_PASSWORD', 'password')),
                'is_active' => true,
                'is_admin' => false,
                'pushover_key' => env('TRADER_B_PUSHOVER_KEY'),
                'notification_channels' => ['mail', 'pushover'],
            ]
        );

        // Create Binance account for this user
        $existingBinanceAccount = Account::where('user_id', $binanceUser->id)
            ->where('api_system_id', $binanceApiSystem->id)
            ->first();

        if (! $existingBinanceAccount) {
            Account::create([
                'uuid' => (string) Str::uuid(),
                'name' => 'Binance Only Account',
                'user_id' => $binanceUser->id,
                'api_system_id' => $binanceApiSystem->id,
                'portfolio_quote' => 'USDT',
                'trading_quote' => 'USDT',
                'trade_configuration_id' => 1,
                'binance_api_key' => env('TRADER_B_BINANCE_API_KEY'),
                'binance_api_secret' => env('TRADER_B_BINANCE_API_SECRET'),
                'market_order_margin_percentage_long' => '5.00',
                'market_order_margin_percentage_short' => '5.00',
            ]);
        }
    }

    /**
     * Setup KuCoin integration: Create KuCoin user and account.
     */
    public function setupKucoinIntegration(ApiSystem $kucoinApiSystem): void
    {
        // Create KuCoin user (separate from other exchange traders)
        $kucoinEmail = env('TRADER_KC_EMAIL');

        if (! $kucoinEmail) {
            return;
        }

        $kucoinUser = User::updateOrCreate(
            ['email' => $kucoinEmail],
            [
                'name' => env('TRADER_KC_NAME'),
                'password' => bcrypt(env('TRADER_KC_PASSWORD', 'password')),
                'is_active' => true,
                'is_admin' => false,
                'pushover_key' => env('TRADER_KC_PUSHOVER_KEY'),
                'notification_channels' => ['mail', 'pushover'],
            ]
        );

        // Create KuCoin account for this user
        $existingKucoinAccount = Account::where('user_id', $kucoinUser->id)
            ->where('api_system_id', $kucoinApiSystem->id)
            ->first();

        if (! $existingKucoinAccount) {
            Account::create([
                'uuid' => (string) Str::uuid(),
                'name' => 'Main KuCoin Account',
                'user_id' => $kucoinUser->id,
                'api_system_id' => $kucoinApiSystem->id,
                'portfolio_quote' => 'USDT',
                'trading_quote' => 'USDT',
                'trade_configuration_id' => 1,
                'kucoin_api_key' => env('TRADER_KC_API_KEY'),
                'kucoin_api_secret' => env('TRADER_KC_API_SECRET'),
                'kucoin_passphrase' => env('TRADER_KC_PASSPHRASE'),
                'market_order_margin_percentage_long' => '5.00',
                'market_order_margin_percentage_short' => '5.00',
            ]);
        }
    }

    /**
     * Setup BitGet integration: Create BitGet user and account.
     */
    public function setupBitgetIntegration(ApiSystem $bitgetApiSystem): void
    {
        // Create BitGet user (separate from other exchange traders)
        $bitgetEmail = env('TRADER_BG_EMAIL');

        if (! $bitgetEmail) {
            return;
        }

        $bitgetUser = User::updateOrCreate(
            ['email' => $bitgetEmail],
            [
                'name' => env('TRADER_BG_NAME'),
                'password' => bcrypt(env('TRADER_BG_PASSWORD', 'password')),
                'is_active' => true,
                'is_admin' => false,
                'pushover_key' => env('TRADER_BG_PUSHOVER_KEY'),
                'notification_channels' => ['mail', 'pushover'],
            ]
        );

        // Create BitGet account for this user
        $existingBitgetAccount = Account::where('user_id', $bitgetUser->id)
            ->where('api_system_id', $bitgetApiSystem->id)
            ->first();

        if (! $existingBitgetAccount) {
            Account::create([
                'uuid' => (string) Str::uuid(),
                'name' => 'Main BitGet Account',
                'user_id' => $bitgetUser->id,
                'api_system_id' => $bitgetApiSystem->id,
                'portfolio_quote' => 'USDT',
                'trading_quote' => 'USDT',
                'trade_configuration_id' => 1,
                'bitget_api_key' => env('TRADER_BG_API_KEY'),
                'bitget_api_secret' => env('TRADER_BG_API_SECRET'),
                'bitget_passphrase' => env('TRADER_BG_PASSPHRASE'),
                'market_order_margin_percentage_long' => '5.00',
                'market_order_margin_percentage_short' => '5.00',
            ]);
        }
    }

    /**
     * Cleanup Bybit credentials from Binance account.
     */
    public function cleanupAccountCredentials(): void
    {
        $binanceAccount = Account::find(1);

        if ($binanceAccount) {
            $binanceAccount->update([
                'bybit_api_key' => null,
                'bybit_api_secret' => null,
            ]);
        }

        // Ensure account 2 (Bybit) has credentials
        $bybitAccount = Account::find(2);

        if ($bybitAccount) {
            $hasKey = ! empty($bybitAccount->bybit_api_key);
            $hasSecret = ! empty($bybitAccount->bybit_api_secret);

            if (! $hasKey || ! $hasSecret) {
                $bybitAccount->update([
                    'bybit_api_key' => env('BYBIT_API_KEY'),
                    'bybit_api_secret' => env('BYBIT_API_SECRET'),
                ]);
            }
        }
    }

    /**
     * Add notification channels to Kraite.
     */
    public function addNotificationChannels(): void
    {
        $engine = Engine::find(1);

        if ($engine) {
            $engine->bybit_api_key = env('BYBIT_API_KEY');
            $engine->bybit_api_secret = env('BYBIT_API_SECRET');
            $engine->notification_channels = [
                'pushover',
                'mail',
            ];
            $engine->save();
        }
    }

    /**
     * Move admin Pushover key from config to database.
     */
    public function moveAdminPushoverKey(): void
    {
        $engine = Engine::find(1);

        if ($engine) {
            $adminPushoverKey = config('kraite.admin_user_pushover_key');

            if ($adminPushoverKey) {
                $engine->admin_pushover_user_key = $adminPushoverKey;
                $engine->save();
            }
        }
    }

    /**
     * Seed the servers table with the current server.
     * Secrets are read from env vars for health check authentication.
     */
    public function seedServers(): void
    {
        $servers = [
            [
                'hostname' => 'worker5',
                'ip_address' => '157.180.69.25',
                'is_apiable' => true,
                'needs_whitelisting' => true,
                'own_queue_name' => 'worker5',
                'description' => 'Worker server for job processing',
                'type' => 'worker',
                'secret' => env('SERVER_SECRET_WORKER5'),
            ],
            [
                'hostname' => 'worker4',
                'ip_address' => '46.62.156.246',
                'is_apiable' => true,
                'needs_whitelisting' => true,
                'own_queue_name' => 'worker4',
                'description' => 'Worker server for job processing',
                'type' => 'worker',
                'secret' => env('SERVER_SECRET_WORKER4'),
            ],
            [
                'hostname' => 'worker3',
                'ip_address' => '46.62.255.137',
                'is_apiable' => true,
                'needs_whitelisting' => true,
                'own_queue_name' => 'worker3',
                'description' => 'Worker server for job processing',
                'type' => 'worker',
                'secret' => env('SERVER_SECRET_WORKER3'),
            ],
            [
                'hostname' => 'worker2',
                'ip_address' => '37.27.83.74',
                'is_apiable' => true,
                'needs_whitelisting' => true,
                'own_queue_name' => 'worker2',
                'description' => 'Worker server for job processing',
                'type' => 'worker',
                'secret' => env('SERVER_SECRET_WORKER2'),
            ],
            [
                'hostname' => 'worker1',
                'ip_address' => '46.62.215.85',
                'is_apiable' => true,
                'needs_whitelisting' => true,
                'own_queue_name' => 'worker1',
                'description' => 'Worker server for job processing',
                'type' => 'worker',
                'secret' => env('SERVER_SECRET_WORKER1'),
            ],
            [
                'hostname' => 'ingestion',
                'ip_address' => '46.62.203.165',
                'is_apiable' => true,
                'needs_whitelisting' => true,
                'own_queue_name' => 'ingestion',
                'description' => 'Ingestion server - cron & dispatch',
                'type' => 'ingestion',
                'secret' => env('SERVER_SECRET_INGESTION'),
            ],
            [
                'hostname' => 'redis',
                'ip_address' => '46.62.215.70',
                'is_apiable' => false,
                'needs_whitelisting' => false,
                'own_queue_name' => null,
                'description' => 'Redis cache server',
                'type' => 'redis',
                'secret' => null,
            ],
            [
                'hostname' => 'database',
                'ip_address' => '46.62.218.172',
                'is_apiable' => false,
                'needs_whitelisting' => false,
                'own_queue_name' => null,
                'description' => 'Database server',
                'type' => 'database',
                'secret' => null,
            ],
            [
                'hostname' => 'frontend',
                'ip_address' => '65.21.5.150',
                'is_apiable' => false,
                'needs_whitelisting' => false,
                'own_queue_name' => null,
                'description' => 'Frontend application server',
                'type' => 'frontend',
                'secret' => null,
            ],
        ];

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
                'cache_key' => null,
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
     * Run all seeding operations with observers disabled.
     */
    private function runSeeding(): void
    {
        // SECTION 1: Create Indicators (SchemaSeeder1, SchemaSeeder9, SchemaSeeder11)
        $this->seedIndicators();

        // SECTION 2: Create API Systems (SchemaSeeder1)
        $apiSystems = $this->seedApiSystems();

        // SECTION 3: Create User (SchemaSeeder1)
        $trader = $this->seedUser();

        // SECTION 4: Create Default Trade Configuration (SchemaSeeder1)
        $this->seedTradeConfiguration();

        // SECTION 5: Create Binance Account (SchemaSeeder1)
        $this->seedBinanceAccount($trader, $apiSystems['binance']);

        // SECTION 6: Create Initial Symbols (SchemaSeeder1, SchemaSeeder2)
        $this->seedSymbols();

        // SECTION 9: Create StepsDispatcher (SchemaSeeder3, StepsDispatcherSeeder)
        $this->seedStepsDispatchers();

        // SECTION 10: Update Trade Configuration (SchemaSeeder4) - SKIPPED: column doesn't exist in final schema
        // $this->updateTradeConfiguration();

        // SECTION 11: Create Kraite (SchemaSeeder5)
        $this->seedKraite();

        // SECTION 12: Add Additional Symbol Batches (SchemaSeeder6-8, SchemaSeeder13-15, SchemaSeeder18)
        $this->seedAdditionalSymbols();

        // SECTION 13: Update Existing Positions (SchemaSeeder10)
        $this->updatePositionProfitPrices();

        // SECTION 14: Update Exchange Symbols (SchemaSeeder2, SchemaSeeder12)
        $this->updateExchangeSymbols();

        // SECTION 15: Migrate Account Credentials (SchemaSeeder16)
        $this->migrateAccountCredentials();

        // SECTION 16: Migrate Kraite Credentials (SchemaSeeder17)
        $this->migrateKraiteCredentials();

        // SECTION 17: Setup Bybit Integration (SchemaSeeder19, SchemaSeeder20, SchemaSeeder21)
        $this->setupBybitIntegration($trader, $apiSystems['bybit']);

        // SECTION 17b: Setup KuCoin Integration (separate user and account)
        $this->setupKucoinIntegration($apiSystems['kucoin']);

        // SECTION 17d: Setup BitGet Integration (separate user and account)
        $this->setupBitgetIntegration($apiSystems['bitget']);

        // SECTION 17e: Setup Binance-only Integration (separate user and account)
        $this->setupBinanceOnlyIntegration($apiSystems['binance']);

        // SECTION 18: Cleanup Bybit Account Credentials (SchemaSeeder22)
        $this->cleanupAccountCredentials();

        // SECTION 19: Add Notification Channels (SchemaSeeder23)
        $this->addNotificationChannels();

        // SECTION 20: Move Admin Pushover Key (SchemaSeeder24)
        $this->moveAdminPushoverKey();

        // SECTION 21: Seed Servers
        $this->seedServers();

        // SECTION 22: Seed Notifications
        $this->seedNotifications();

        // SECTION 23: Seed Token Mappers (Binance to other exchanges name mappings)
        $this->seedTokenMappers($apiSystems);

        // SECTION 24: Seed Core Symbol Data (symbols, exchange_symbols)
        $this->seedCoreSymbolData();

        // SECTION 25: Activate only the testing account (see method for account IDs)
        $this->deactivateNonPrimaryAccounts();
    }

    /**
     * Deactivate all accounts except the testing account.
     * Change the active account ID here to test different exchanges.
     */
    public function deactivateNonPrimaryAccounts(): void
    {
        // Activate all accounts
        // 1 = Binance, 2 = Bybit, 3 = KuCoin, 4 = BitGet, 5 = Binance Only
        Account::query()->update(['is_active' => true]);
    }

    /**
     * Seed subscription plans.
     */
    public function seedSubscriptions(): void
    {
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
     * Seed symbols table from SQL dump.
     * Exchange symbols are populated separately via cronjobs:refresh-exchange-symbols.
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
