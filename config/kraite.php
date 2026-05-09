<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Admin User
    |--------------------------------------------------------------------------
    */
    'admin_user_name' => env('ADMIN_USER_NAME'),
    'admin_user_email' => env('ADMIN_USER_EMAIL'),
    'admin_user_password' => env('ADMIN_USER_PASSWORD', 'password'),
    'admin_user_pushover_application_key' => env('ADMIN_USER_PUSHOVER_APPLICATION_KEY'),
    'admin_user_pushover_user_key' => env('ADMIN_USER_PUSHOVER_USER_KEY'),
    'admin_user_pushover_key' => env('ADMIN_USER_PUSHOVER_KEY'),
    'admin_user_telegram_chat_id' => env('ADMIN_USER_TELEGRAM_CHAT_ID'),

    /*
    |--------------------------------------------------------------------------
    | User Data Stream
    |--------------------------------------------------------------------------
    |
    | Per-exchange daemon settings for the authenticated private WebSocket
    | streams that progressively replace polling-based reconciliation.
    |
    | `dispatched_executions` is the per-execution-type allowlist. Each
    | entry is the EXCHANGE-NATIVE execution type string. When an inbound
    | event's executionType matches an entry in this list, the worker
    | applies the new state to the local Order model via updateSaving,
    | which fires the existing OrderObserver workflows (correction, WAP,
    | close, replacement). Execution types NOT in the list are recorded
    | to api_data_stream for audit only — the Order model is not touched.
    |
    | Roll-out flips one execution type at a time so each downstream
    | workflow can be validated against live events before the next
    | type is enabled. Empty list = pure shadow mode (record only).
    |
    | Binance native types: NEW / TRADE / AMENDMENT / CANCELED / EXPIRED
    | / REJECTED / CALCULATED. NOT every type maps to a downstream
    | workflow today (e.g. NEW is informational — we placed the order,
    | so we already know).
    */
    'user_data_stream' => [
        'binance' => [
            'dispatched_executions' => array_values(array_filter(
                array_map('trim', explode(',', (string) env('USER_DATA_STREAM_BINANCE_DISPATCHED_EXECUTIONS', ''))),
                static fn (string $value): bool => $value !== ''
            )),

            // Listen-key keepalive cadence enforced by the cron itself; this
            // value is the soft warning threshold after which a keepalive
            // miss raises an admin alert. Binance's hard expiry is 60min.
            'keep_alive_alert_after_minutes' => (int) env('USER_DATA_STREAM_BINANCE_KEEP_ALIVE_ALERT_AFTER_MIN', 35),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Position Creation
    |--------------------------------------------------------------------------
    |
    | Test-only knobs around position opening. Designed to be left
    | empty in production.
    |
    | `symbol_override` is a god-mode override consumed by the
    | HasTokenDiscovery trait during slot assignment. When the
    | configured account is processed and the configured symbol is
    | resolvable on that account's exchange (no other gate veto), the
    | symbol-selection algorithm is short-circuited and that symbol is
    | assigned to the new slot directly — bypassing scoring,
    | correlation/elasticity filters, BTC-bias rules, and eligibility
    | gates such as `is_manually_enabled` or `was_backtesting_approved`.
    |
    | The override falls back silently to the normal scoring flow when:
    |   - no override is configured
    |   - the override's account_id does not match the current account
    |   - the symbol cannot be resolved on the account's exchange
    |   - the symbol is already part of an active position for this account
    |   - the resolved symbol's direction does not match the slot direction
    |
    | The override does NOT raise slot caps. The bot must still have a
    | free slot in the relevant direction for any selection to happen,
    | overridden or otherwise.
    |
    | Used by Bruno to force a known token onto a freed slot when
    | rehearsing WAP / close / drift flows on a hedge account. Leave
    | the example commented out in production.
    */
    'position_creation' => [
        // 'symbol_override' => [
        //     'account_id' => 5,
        //     'symbol' => 'APEUSDT',
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Server Secrets
    |--------------------------------------------------------------------------
    |
    | Health check authentication secrets for each server.
    */
    'server_secrets' => [
        'worker5' => env('SERVER_SECRET_WORKER5'),
        'worker4' => env('SERVER_SECRET_WORKER4'),
        'worker3' => env('SERVER_SECRET_WORKER3'),
        'worker2' => env('SERVER_SECRET_WORKER2'),
        'worker1' => env('SERVER_SECRET_WORKER1'),
        'ingestion' => env('SERVER_SECRET_INGESTION'),
    ],

    /**
     * Small safety tolerance to lower the leverage bracket in case is
     * falls inside that percentage gap, to avoid last limit order rejections.
     */
    'bracket_headroom_pct' => '0.004',

    /*
    |--------------------------------------------------------------------------
    | Performance / Feature Toggles
    |--------------------------------------------------------------------------
    |
    | slow_query_threshold_ms:  Log queries slower than this (in milliseconds).
    | can_trade:                Global kill-switch. If false, the bot NEVER places live orders.
    | can_open_positions:       If false, existing positions can be managed/closed, but no new ones open.
    | notifications_enabled:    If false, no notifications will be sent (useful for testing).
    */
    'slow_query_threshold_ms' => env('SLOW_QUERY_THRESHOLD_MS', 2500),
    'can_trade' => env('CAN_TRADE', false),
    'can_open_positions' => env('CAN_OPEN_POSITIONS', false),
    'notifications_enabled' => env('NOTIFICATIONS_ENABLED', true),
    'prefix_hostname_on_notifications' => env('PREFIX_HOSTNAME_ON_NOTIFICATIONS', false),
    'can_dispatch_steps' => env('CAN_DISPATCH_STEPS', false),

    /*
    |--------------------------------------------------------------------------
    | Exchange Cooldown
    |--------------------------------------------------------------------------
    |
    | When an exchange reports server instability (e.g., HTTP 503/504), a
    | cooldown period is activated for that exchange. During cooldown, no
    | new positions will be opened, but existing workflows continue normally.
    */
    'cooldown_duration_minutes' => env('EXCHANGE_COOLDOWN_DURATION_MINUTES', 30),

    /*
    |--------------------------------------------------------------------------
    | Health Check Authentication
    |--------------------------------------------------------------------------
    |
    | Secret token for authenticating /health-check endpoint requests.
    | Each server has its own secret; the dashboard sends the token via X-Health-Token header.
    | Leave empty to disable authentication (not recommended in production).
    */
    'health_check_secret' => env('HEALTH_CHECK_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Indicator Batch Processing
    |--------------------------------------------------------------------------
    |
    | jobs_per_index_batch: Number of parallel QueryAllIndicatorsForSymbolsChunkJob
    |                       jobs that can run simultaneously in the same index group.
    |                       Higher values = faster execution but more API rate limit risk.
    |                       Default: 20 (balanced between speed and safety)
    |                       Conservative: 10 (0% rate limiting)
    |                       Aggressive: 30+ (faster but may hit rate limits)
    */
    'indicators' => [
        'jobs_per_index_batch' => (int) env('INDICATORS_JOBS_PER_INDEX_BATCH', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | Token Discovery
    |--------------------------------------------------------------------------
    |
    | sr_safe_zone: width of the S/R proximity penalty band as a fraction
    |               of the R1-S1 range. 0.20 = "reject / penalise candidates
    |               whose mark_price is in the outer 20 % toward the
    |               wrong-side level for their concluded direction".
    |               LONG gets penalised as position_in_range → 1.0 (near R1);
    |               SHORT gets penalised as position_in_range → 0.0 (near S1).
    |               Breakouts past R3 / S3 are handled direction-aware and
    |               bypass this band.
    |               Default: 0.20.
    */
    'token_discovery' => [
        'sr_safe_zone' => (float) env('TOKEN_DISCOVERY_SR_SAFE_ZONE', 0.20),
        'correlation_type' => env('TOKEN_DISCOVERY_CORRELATION_TYPE', 'rolling'),

        // Stale-mark-price freshness gate. A candidate symbol whose
        // sidecar `mark_price_synced_at` is older than this threshold
        // is dropped from the assignment pool BEFORE scoring — so a
        // daemon stall produces zero opens across every account in
        // the same tick instead of a wave of stale-priced bad picks.
        // Null sidecar (legacy column / brand-new symbol / test
        // fixture) is allowed through; the throwing computations in
        // HasTradingComputations catch the null-everything case
        // downstream as defence in depth. Set to 0 to disable.
        'mark_price_max_age_seconds' => (int) env('TOKEN_DISCOVERY_MARK_PRICE_MAX_AGE_SECONDS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Health Watchdog
    |--------------------------------------------------------------------------
    |
    | orphan_kraite_match_window_minutes:
    |   When `accounts.allow_other_orders=true` the orphan-cleanup
    |   watchdog cancels exchange orders only when they match the
    |   `client_order_id` of a Kraite Order linked to a position that
    |   transitioned to a terminal state within this rolling window.
    |   Older orphans are treated as user-placed and left alone.
    |
    |   The watchdog itself runs every 5 minutes inside
    |   `kraite:cron-check-system-health`, so a 60-minute window
    |   gives 12 ticks of coverage for the typical post-close
    |   ladder-leftover scenario without spilling into truly
    |   long-stale orders.
    */
    'health_watchdog' => [
        'orphan_kraite_match_window_minutes' => (int) env(
            'HEALTH_WATCHDOG_ORPHAN_MATCH_WINDOW_MINUTES',
            60
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | API Throttlers
    |--------------------------------------------------------------------------
    |
    | Rate limiting configuration for external API providers.
    | Each throttler enforces requests per window and minimum delays between requests.
    |
    | TAAPI.IO Throttler:
    | - Expert Plan: 75 requests per 15 seconds (300/min, 18,000/hour)
    | - Advanced Plan: 60 requests per 15 seconds (240/min, 14,400/hour)
    | - Basic Plan: 30 requests per 15 seconds (120/min, 7,200/hour)
    | - Adjust based on your plan tier
    |
    | PROFILE GUIDE (Expert Plan examples):
    | Conservative (80% capacity): 60 req/15s, 250ms delay
    | Balanced (90% capacity): 68 req/15s, 225ms delay
    | Aggressive (95% capacity): 71 req/15s, 200ms delay
    |
    | CoinMarketCap Throttler:
    | - Free/Hobbyist: 30 requests per minute
    | - Startup: 60 requests per minute
    | - Standard: 90 requests per minute
    | - Professional: 120 requests per minute
    | - Enterprise: 120+ requests per minute
    | - Adjust based on your plan tier
    |
    | Binance Throttler (IP-based coordination):
    | - Uses dynamic rate limiting based on response headers
    | - Three limit types: RAW_REQUESTS, REQUEST_WEIGHT, ORDERS
    | - Multiple intervals: 1S, 10S, 1M, 2M, 5M, 10M, 1H, 1D
    | - Defaults below are conservative for standard Binance Futures API
    | - Rate limits are IP-based and coordinated across workers via Cache
    */
    'throttlers' => [
        'taapi' => [
            // Maximum requests allowed per window (based on your TAAPI plan)
            'requests_per_window' => (int) env('TAAPI_THROTTLER_REQUESTS_PER_WINDOW', 75),

            // Window size in seconds (TAAPI uses 15-second windows)
            'window_seconds' => (int) env('TAAPI_THROTTLER_WINDOW_SECONDS', 15),

            // Minimum delay between consecutive requests in milliseconds
            'min_delay_between_requests_ms' => (int) env('TAAPI_THROTTLER_MIN_DELAY_MS', 50),

            // Safety threshold: stop at this percentage of limit (0.0-1.0)
            // 1.0 = match TAAPI's actual window cap exactly. Relies on our
            // throttler being sub-window accurate — verified under 1000-step
            // stress to hit 92% of TAAPI's real cap with only ~200 probe 429s,
            // all cleanly handled by the is_throttled reschedule path.
            'safety_threshold' => (float) env('TAAPI_THROTTLER_SAFETY_THRESHOLD', 1.0),

            // Bulk API construct limit (number of constructs per /bulk request)
            // This determines how many symbols are batched into a single API call.
            // TAAPI Plan Limits:
            // - Pro: 3 constructs per request
            // - Expert: 10 constructs per request
            // - Max: 20 constructs per request
            // Higher values = fewer API calls but larger payload per request
            'bulk_constructs_limit' => (int) env('TAAPI_BULK_CONSTRUCTS_LIMIT', 10),
        ],

        'coinmarketcap' => [
            'requests_per_window' => (int) env('COINMARKETCAP_THROTTLER_REQUESTS_PER_WINDOW', 30),
            'window_seconds' => (int) env('COINMARKETCAP_THROTTLER_WINDOW_SECONDS', 60),
            'min_delay_between_requests_ms' => (int) env('COINMARKETCAP_THROTTLER_MIN_DELAY_MS', 2000),
        ],

        'binance' => [
            // Minimum delay between requests in milliseconds
            'min_delay_ms' => (int) env('BINANCE_THROTTLER_MIN_DELAY_MS', 200),

            // Safety threshold: stop making requests when reaching this percentage of limit (0.0-1.0)
            // 0.85 = stop at 85% to leave 15% buffer before hitting the limit
            // Higher values = more aggressive (use more of available capacity)
            // Lower values = more conservative (larger safety buffer)
            'safety_threshold' => (float) env('BINANCE_THROTTLER_SAFETY_THRESHOLD', 0.85),

            // Rate limit definitions for pre-flight safety checks
            // These are checked against response header values stored in Cache
            // Type can be: REQUEST_WEIGHT or ORDERS
            // Interval format: {number}{unit} where unit is s/m/h/d (e.g., "10s", "1m")
            //
            // BINANCE FUTURES API OFFICIAL LIMITS (as of 2025):
            // - REQUEST_WEIGHT: 2400/minute, 300/10s (IP-based)
            // - ORDERS: 1200/minute, 300/10s (UID-based, per account)
            //
            // PROFILE GUIDE:
            // Conservative (50% capacity): 1200 weight/min, 150 weight/10s, 150 orders/10s
            // Balanced (85% capacity): 2040 weight/min, 255 weight/10s, 255 orders/10s
            // Aggressive (95% capacity): 2280 weight/min, 285 weight/10s, 285 orders/10s
            // Maximum (100% capacity): 2400 weight/min, 300 weight/10s, 300 orders/10s
            //
            // IMPORTANT: Adjust based on your Binance VIP tier and trading volume
            // VIP tiers may have higher limits - check Binance documentation for your tier
            'rate_limits' => [
                [
                    'type' => 'REQUEST_WEIGHT',
                    'interval' => '1m',
                    'limit' => (int) env('BINANCE_WEIGHT_LIMIT_1M', 2040), // 85% of 2400
                ],
                [
                    'type' => 'REQUEST_WEIGHT',
                    'interval' => '10s',
                    'limit' => (int) env('BINANCE_WEIGHT_LIMIT_10S', 255), // 85% of 300
                ],
                [
                    'type' => 'ORDERS',
                    'interval' => '1m',
                    'limit' => (int) env('BINANCE_ORDERS_LIMIT_1M', 1020), // 85% of 1200
                ],
                [
                    'type' => 'ORDERS',
                    'interval' => '10s',
                    'limit' => (int) env('BINANCE_ORDERS_LIMIT_10S', 255), // 85% of 300
                ],
            ],

            // Advanced settings
            'advanced' => [
                // Track weight-based metrics (instead of just request count)
                // When true, throttler considers endpoint weights (e.g., /fapi/v2/positionRisk = 5 weight)
                'track_weight' => (bool) env('BINANCE_TRACK_WEIGHT', true),

                // Track order count per account (UID-based limits)
                // When true, throttler monitors per-account order placement limits
                'track_orders_per_account' => (bool) env('BINANCE_TRACK_ORDERS_PER_ACCOUNT', false),

                // Automatically fetch current rate limits from /fapi/v1/exchangeInfo
                // When true, system periodically updates limits based on Binance's live values
                'auto_fetch_limits' => (bool) env('BINANCE_AUTO_FETCH_LIMITS', false),
            ],
        ],

        'bybit' => [
            // Minimum delay between requests in milliseconds
            'min_delay_ms' => (int) env('BYBIT_THROTTLER_MIN_DELAY_MS', 200),

            // Safety threshold: stop making requests when remaining falls below this percentage (0.0-1.0)
            // 0.15 = stop when less than 15% of requests remaining to leave buffer
            // Note: Bybit uses "remaining" not "used", so LOWER threshold means MORE conservative
            // Higher values = more conservative (stop earlier when more requests remain)
            // Lower values = more aggressive (keep going until fewer requests remain)
            'safety_threshold' => (float) env('BYBIT_THROTTLER_SAFETY_THRESHOLD', 0.15),

            // Rate limit configuration (fallback when headers unavailable)
            // BYBIT API OFFICIAL LIMITS (as of 2025):
            // - HTTP Level: 600 requests per 5 seconds per IP (hard limit, triggers 403 ban)
            // - API Level: Varies by endpoint and account tier
            //
            // PROFILE GUIDE:
            // Conservative (83% capacity): 500 req/5s, 200ms delay
            // Balanced (92% capacity): 550 req/5s, 100ms delay
            // Aggressive (97% capacity): 580 req/5s, 50ms delay
            'requests_per_window' => (int) env('BYBIT_THROTTLER_REQUESTS_PER_WINDOW', 550), // 92% of 600
            'window_seconds' => (int) env('BYBIT_THROTTLER_WINDOW_SECONDS', 5),
        ],

        'kraken' => [
            // Minimum delay between requests in milliseconds
            'min_delay_ms' => (int) env('KRAKEN_THROTTLER_MIN_DELAY_MS', 200),

            // Safety threshold: stop making requests when reaching this percentage of limit (0.0-1.0)
            // 0.85 = stop at 85% to leave 15% buffer before hitting the limit
            'safety_threshold' => (float) env('KRAKEN_THROTTLER_SAFETY_THRESHOLD', 0.85),

            // Rate limit configuration
            // KRAKEN FUTURES API OFFICIAL LIMITS:
            // - 500 requests per 10 seconds per IP
            // - Different tiers for different endpoint types
            //
            // PROFILE GUIDE:
            // Conservative (80% capacity): 400 req/10s, 200ms delay
            // Balanced (85% capacity): 425 req/10s, 100ms delay
            // Aggressive (95% capacity): 475 req/10s, 50ms delay
            'requests_per_window' => (int) env('KRAKEN_THROTTLER_REQUESTS_PER_WINDOW', 425), // 85% of 500
            'window_seconds' => (int) env('KRAKEN_THROTTLER_WINDOW_SECONDS', 10),
        ],

        'kucoin' => [
            // Minimum delay between requests in milliseconds
            'min_delay_ms' => (int) env('KUCOIN_THROTTLER_MIN_DELAY_MS', 100),

            // Safety threshold: stop making requests when reaching this percentage of limit (0.0-1.0)
            // 0.85 = stop at 85% to leave 15% buffer before hitting the limit
            'safety_threshold' => (float) env('KUCOIN_THROTTLER_SAFETY_THRESHOLD', 0.85),

            // Rate limit configuration
            // KUCOIN FUTURES API OFFICIAL LIMITS:
            // - Public endpoints: 30 requests per 3 seconds per IP
            // - Private endpoints: 75 requests per 3 seconds per IP
            // - We use the more conservative public limit
            //
            // PROFILE GUIDE:
            // Conservative (80% capacity): 24 req/3s, 125ms delay
            // Balanced (85% capacity): 25 req/3s, 100ms delay
            // Aggressive (95% capacity): 28 req/3s, 50ms delay
            'requests_per_window' => (int) env('KUCOIN_THROTTLER_REQUESTS_PER_WINDOW', 25), // 85% of 30
            'window_seconds' => (int) env('KUCOIN_THROTTLER_WINDOW_SECONDS', 3),
        ],

        'bitget' => [
            // Minimum delay between requests in milliseconds
            'min_delay_ms' => (int) env('BITGET_THROTTLER_MIN_DELAY_MS', 50),

            // Safety threshold: stop making requests when reaching this percentage of limit (0.0-1.0)
            // 0.85 = stop at 85% to leave 15% buffer before hitting the limit
            'safety_threshold' => (float) env('BITGET_THROTTLER_SAFETY_THRESHOLD', 0.85),

            // Rate limit configuration
            // BITGET FUTURES API OFFICIAL LIMITS:
            // - Overall: 6000 requests per minute per IP
            // - Public endpoints: 20 requests per second per IP
            // - Private endpoints: 10 requests per second for orders
            // - We use a conservative per-minute limit
            //
            // PROFILE GUIDE:
            // Conservative (75% capacity): 75 req/min, 100ms delay
            // Balanced (85% capacity): 90 req/min, 50ms delay
            // Aggressive (95% capacity): 100 req/min, 25ms delay
            'requests_per_window' => (int) env('BITGET_THROTTLER_REQUESTS_PER_WINDOW', 90), // Conservative for our workload
            'window_seconds' => (int) env('BITGET_THROTTLER_WINDOW_SECONDS', 60),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Live API Credentials (NOT per-account exchange creds)
    |--------------------------------------------------------------------------
    |
    | api.credentials.*: Service-level API keys used by background jobs (market data,
    | indicators, metadata, notifications, etc.). These are NOT your trading sub-accounts.
    */
    'api' => [
        'url' => [
            'binance' => [
                'rest' => 'https://fapi.binance.com',
                'stream' => 'wss://fstream.binance.com',
            ],

            'bybit' => [
                'rest' => 'https://api.bybit.com',
                'stream' => 'wss://stream.bybit.com',
            ],

            'kraken' => [
                'rest' => 'https://futures.kraken.com',
                'stream' => 'wss://futures.kraken.com/ws/v1',
            ],

            'kucoin' => [
                'rest' => 'https://api-futures.kucoin.com',
                // WebSocket URL is dynamic - obtained from /api/v1/bullet-public endpoint
            ],

            'bitget' => [
                'rest' => 'https://api.bitget.com',
                'stream' => 'wss://ws.bitget.com/v2/ws/public',
            ],

            'alternativeme' => [
                'rest' => 'https://api.alternative.me',
            ],

            'coinmarketcap' => [
                'rest' => 'https://pro-api.coinmarketcap.com',
            ],

            'taapi' => [
                'rest' => 'https://api.taapi.io',
            ],
        ],

        // Pushover configuration for notifications
        'pushover' => [
            // Delivery groups
            // Each group maps to a Pushover delivery group with its configuration
            // Priority levels: -2 (lowest), -1 (low), 0 (normal), 1 (high), 2 (emergency)
            'delivery_groups' => [
                'exceptions' => [
                    'group_key' => env('PUSHOVER_DG_EXCEPTIONS'),
                    'priority' => 2, // Emergency priority with siren sound
                ],
                'default' => [
                    'group_key' => env('PUSHOVER_DG_DEFAULT'),
                    'priority' => 0, // Normal priority
                ],
                'indicators' => [
                    'group_key' => env('PUSHOVER_DG_INDICATORS'),
                    'priority' => 0, // Normal priority
                ],
            ],
        ],

        // Webhook URLs for notification delivery confirmations
        // Used by external gateways to confirm delivery/bounces/opens
        'webhooks' => [
            // Zeptomail webhook URL (receives: hard bounce, soft bounce, open events)
            // Configure in Zeptomail dashboard: Settings > Webhooks
            // Example: https://your-domain.com/api/webhooks/zeptomail/events
            'zeptomail' => env('ZEPTOMAIL_WEBHOOK_URL'),

            // Zeptomail webhook secret for signature verification
            // Get this from Zeptomail dashboard: Settings > Webhooks > Secret Key
            'zeptomail_secret' => env('ZEPTOMAIL_WEBHOOK_SECRET'),

            // Pushover callback URL (for emergency-priority receipt acknowledgment)
            // Used as 'callback' parameter when sending emergency notifications
            // Example: https://your-domain.com/api/webhooks/pushover/receipt
            'pushover' => env('PUSHOVER_WEBHOOK_URL'),
        ],

        'credentials' => [

            // Live Binance keys (service-level; NOT user account keys used to place orders).
            'binance' => [
                'api_key' => env('BINANCE_API_KEY'),
                'api_secret' => env('BINANCE_API_SECRET'),
            ],

            // Live Bybit keys (service-level; NOT user account keys used to place orders).
            'bybit' => [
                'api_key' => env('BYBIT_API_KEY'),
                'api_secret' => env('BYBIT_API_SECRET'),
            ],

            // Live Kraken Futures keys (service-level; NOT user account keys used to place orders).
            'kraken' => [
                'api_key' => env('KRAKEN_API_KEY'),
                'private_key' => env('KRAKEN_PRIVATE_KEY'),
            ],

            // Live KuCoin Futures keys (service-level; NOT user account keys used to place orders).
            'kucoin' => [
                'api_key' => env('KUCOIN_API_KEY'),
                'api_secret' => env('KUCOIN_API_SECRET'),
                'passphrase' => env('KUCOIN_PASSPHRASE'),
            ],

            // Live BitGet Futures keys (service-level; NOT user account keys used to place orders).
            'bitget' => [
                'api_key' => env('BITGET_API_KEY'),
                'api_secret' => env('BITGET_API_SECRET'),
                'passphrase' => env('BITGET_PASSPHRASE'),
            ],

            // TAAPI indicator provider.
            'taapi' => [
                'secret' => env('TAAPI_SECRET'),
            ],

            // CoinMarketCap metadata provider.
            'coinmarketcap' => [
                'api_key' => env('COINMARKETCAP_API_KEY'),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Market Regime — Black Swan Composite Score (BSCS)
    |--------------------------------------------------------------------------
    |
    | Phase 1 telemetry only. Hourly job computes a portfolio-level fragility
    | score from BTC + 4 reference alts on 1h klines and persists a snapshot.
    | Phase 2 wires the score into HasTradingGuards + PreparePositionData.
    |
    | Spec: ~/docs/kraite/black-swan-logic.md
    */
    'market_regime' => [
        'symbols' => explode(',', (string) env(
            'MARKET_REGIME_SYMBOLS',
            'BTCUSDT,ETHUSDT,SOLUSDT,BNBUSDT,XRPUSDT'
        )),
        'timeframe' => env('MARKET_REGIME_TIMEFRAME', '1h'),
        'baseline_days' => (int) env('MARKET_REGIME_BASELINE_DAYS', 14),
        'thresholds' => [
            'vol_expansion' => (float) env('MARKET_REGIME_VOL_EXPANSION', 1.30),
            'range_blowout' => (float) env('MARKET_REGIME_RANGE_BLOWOUT', 1.50),
            'corr_regime' => (float) env('MARKET_REGIME_CORR_REGIME', 0.70),
            'rejection_pct' => (float) env('MARKET_REGIME_REJECTION_PCT', -5.0),
            'fut_vol' => (float) env('MARKET_REGIME_FUT_VOL', 1.20),
        ],
        'block_threshold' => (int) env('MARKET_REGIME_BLOCK_THRESHOLD', 80),
        'freshness_max_seconds' => (int) env('MARKET_REGIME_FRESHNESS_MAX_SECONDS', 6900),
        'fragile' => [
            'lower_bound' => (int) env('MARKET_REGIME_FRAGILE_LOWER', 60),
            'upper_bound' => (int) env('MARKET_REGIME_FRAGILE_UPPER', 79),
            'max_reduction_pct' => (int) env('MARKET_REGIME_FRAGILE_MAX_REDUCTION', 50),
        ],
        // Phase 2.1C — directional crowding multiplier. Downscales margin
        // on the side of the book that already carries >= `threshold`
        // share of the total notional exposure. Empty side stays NEUTRAL
        // (1.0×) until backtest evidence justifies an upscale.
        'crowding' => [
            'threshold' => (float) env('MARKET_REGIME_CROWDING_THRESHOLD', 0.70),
            'max_reduction_pct' => (float) env('MARKET_REGIME_CROWDING_MAX_REDUCTION', 50),
        ],
        'override' => [
            'default_hours' => (int) env('MARKET_REGIME_OVERRIDE_DEFAULT_HOURS', 4),
            'max_hours' => (int) env('MARKET_REGIME_OVERRIDE_MAX_HOURS', 24),
        ],
        // Fast cascade-in-progress detector. Distinct from BSCS (slow,
        // hourly) — runs every minute on 15m klines for BTC + 4 alts to
        // catch cliffs that the BSCS hourly cron would only see at the
        // next :50 tick. When any rule fires, arms the SHARED
        // `bscs_cooldown_until` column for `cooldown_hours` (default
        // 24h, same as BSCS). Notification: `market_shock_circuit_breaker`
        // — fires once per fresh arming, silent re-arm while a cooldown
        // is already active to avoid double-pinging.
        'shock' => [
            'thresholds' => [
                'btc_15m_pct' => (float) env('MARKET_SHOCK_BTC_15M_PCT', -3.0),
                'btc_1h_pct' => (float) env('MARKET_SHOCK_BTC_1H_PCT', -5.0),
                'alt_basket_1h_pct' => (float) env('MARKET_SHOCK_ALT_BASKET_1H_PCT', -7.0),
                'corr_1h' => (float) env('MARKET_SHOCK_CORR_1H', 0.85),
                'magnitude_pct' => (float) env('MARKET_SHOCK_MAGNITUDE_PCT', 3.0),
            ],
            'cooldown_hours' => (int) env('MARKET_SHOCK_COOLDOWN_HOURS', 24),
        ],

        // Cooldown wired to `kraite:cron-analyse-bscs`. When the latest
        // composite score reaches or exceeds `cooldown_threshold` AND no
        // cooldown is currently active, the analyse cron sets
        // `kraite.bscs_cooldown_until = now() + cooldown_hours` and emits
        // the `market_regime_critical` notification once. While the
        // timestamp is in the future, `HasTradingGuards::canOpenPositions()`
        // blocks new opens. On natural expiry the next analyse run
        // re-checks: still high → another `cooldown_hours` window;
        // recovered → cooldown ends and `market_regime_recovered` fires.
        'cooldown' => [
            'threshold' => (int) env('MARKET_REGIME_COOLDOWN_THRESHOLD', 80),
            'hours' => (int) env('MARKET_REGIME_COOLDOWN_HOURS', 24),
        ],
        // Three-tier freshness model (Phase 2.1B).
        //
        //   age <= freshness_max_seconds (kraite singleton, default 6900)
        //     → Fresh, gate runs normally.
        //
        //   freshness_max_seconds < age <= stale_hard_seconds
        //     → StaleSoft, last cooldown state preserved, warn once.
        //
        //   age > stale_hard_seconds  (default 6h)
        //     → StaleHard, gate fails open, market_regime_compute_stale fires.
        'freshness' => [
            'stale_hard_seconds' => (int) env('MARKET_REGIME_STALE_HARD_SECONDS', 21600),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Billing
    |--------------------------------------------------------------------------
    |
    | enforce_minimum_for_renewal
    |   When true (production default), the /billing top-up button is
    |   sized by the coverage rule — shortfall to next renewal, or the
    |   engine's flat floor when wallet covers — capped against the
    |   gateway's per-coin floor.
    |
    |   When false, the coverage rule is bypassed entirely and the
    |   top-up amount falls back to the gateway's per-coin minimum
    |   only. Useful for testing with small amounts (e.g. ~11 USDT on
    |   USDT-TRC20) without funding a full subscription month upfront.
    |   The renewal logic itself is unaffected — it always pulls
    |   monthly_rate from the wallet at renewal time regardless of
    |   this flag.
    */
    'billing' => [
        'enforce_minimum_for_renewal' => (bool) env('BILLING_ENFORCE_MINIMUM_FOR_RENEWAL', true),
    ],

    'server_role' => env('SERVER_ROLE', 'web'),
];
