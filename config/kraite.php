<?php

declare(strict_types=1);

$appUrl = mb_rtrim((string) env('APP_URL', 'https://kraite.com'), '/');
$derivedWebsiteUrl = preg_replace('#^(https?://)admin\.#', '$1', $appUrl) ?? $appUrl;
$websiteUrl = mb_rtrim((string) env('KRAITE_WEBSITE_URL', $derivedWebsiteUrl), '/');

return [

    /*
    |--------------------------------------------------------------------------
    | Admin User
    |--------------------------------------------------------------------------
    */
    'php_binary' => env('PHP_CLI_BINARY', 'php'),

    'ingestion_path' => env('KRAITE_INGESTION_PATH', is_dir('/home/waygou/ingestion.kraite.com')
        ? '/home/waygou/ingestion.kraite.com'
        : base_path('../ingestion.kraite.com')),

    'ingestion_ssh_host' => env('KRAITE_INGESTION_SSH_HOST'),
    'ingestion_ssh_user' => env('KRAITE_INGESTION_SSH_USER', 'waygou'),
    'ingestion_ssh_key' => env('KRAITE_INGESTION_SSH_KEY'),
    'ingestion_remote_path' => env('KRAITE_INGESTION_REMOTE_PATH', '/home/waygou/ingestion.kraite.com'),

    'admin_user_name' => env('ADMIN_USER_NAME'),
    'admin_user_email' => env('ADMIN_USER_EMAIL'),
    'admin_user_password' => env('ADMIN_USER_PASSWORD', 'password'),
    'admin_user_pushover_application_key' => env('ADMIN_USER_PUSHOVER_APPLICATION_KEY'),
    'admin_user_pushover_user_key' => env('ADMIN_USER_PUSHOVER_USER_KEY'),
    'admin_user_pushover_key' => env('ADMIN_USER_PUSHOVER_KEY'),
    'admin_user_telegram_chat_id' => env('ADMIN_USER_TELEGRAM_CHAT_ID'),

    'positions' => [
        // Hours a cleanly-closed position keeps its diagnostic breadcrumb
        // trail (model_logs, api_request_logs, api_snapshots) before the
        // janitor reclaims it. 0 = purge immediately on close. Production
        // sets 24 so the nightly DB backup captures the trail first; the
        // deferred purge is swept by `kraite:cron-purge-position-trails`.
        'trail_retention_hours' => (int) env('KRAITE_TRAIL_RETENTION_HOURS', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Admin URL — canonical password-reset host
    |--------------------------------------------------------------------------
    |
    | Used by the password-reset and private-beta welcome canonicals
    | (NotificationMessageBuilder match arms) to build the reset link.
    | The link always points at admin.kraite.com regardless of which
    | app dispatches the email (kraite.com landing, console, or admin).
    */
    'admin_url' => env('KRAITE_ADMIN_URL', 'https://admin.kraite.com'),

    /*
    |--------------------------------------------------------------------------
    | Website URL
    |--------------------------------------------------------------------------
    |
    | Public website host used for legal and marketing links rendered outside
    | the marketing app. Defaults to APP_URL, with admin.* hosts mapped back
    | to the public domain for local and production admin panels.
    */
    'website_url' => $websiteUrl,

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

    /*
    |--------------------------------------------------------------------------
    | Fleet — canonical hostname → IP map (matches servers table)
    |--------------------------------------------------------------------------
    |
    | Single source of truth for the production fleet roster. Sibling to
    | `kraite.horizon.workers` (which only describes Horizon supervisor
    | topology). Read by `KraiteSeeder::seedServers()` to populate the
    | `servers` table on every fresh seed, and indirectly by the deploy-time
    | drift gate `kraite:verify-fleet-topology` which checks that every
    | `kraite.horizon.workers` key has a matching `servers.hostname` row.
    |
    | Why this lives alongside `horizon.workers` instead of inside it: the
    | two domains describe different things. `horizon.workers` describes
    | per-supervisor process counts. `fleet.servers` describes physical
    | hosts (IPs, roles, apiable flag). Hyperion belongs in `fleet.servers`
    | (it's a host) but NOT in `horizon.workers` (it runs no Horizon).
    | Keeping them separate keeps each block small + obviously-purposed.
    |
    | Adding a worker = one edit here (new hostname row) AND one edit in
    | `horizon.workers` (supervisor block). The drift gate at deploy step
    | 10 catches half-edits.
    |
    | `is_apiable`: true for hosts that make outbound exchange API calls
    | (the workers + the ingestion box's WS daemons). False for storage-
    | only hosts (hyperion = MySQL + Redis). The drift gate rejects orphan
    | apiable rows (server provisioned but no horizon.workers block).
    */
    'fleet' => [
        'servers' => [
            'local' => [
                'ip_address' => '127.0.0.1',
                'is_apiable' => true,
                'type' => 'local',
                'description' => 'Local dev box (Bruno\'s Mac)',
            ],
            'hyperion' => [
                'ip_address' => '135.181.93.226',
                'is_apiable' => false,
                'type' => 'database',
                'description' => 'Database (MySQL) + Redis',
            ],
            'athena' => [
                'ip_address' => '37.27.243.164',
                'is_apiable' => true,
                'type' => 'ingestion',
                'description' => 'Ingestion (scheduler + dispatch-daemon + WS daemons + user-data Horizon). Web stack moved to pheme 2026-06-01.',
            ],
            'pheme' => [
                'ip_address' => '62.238.38.113',
                'is_apiable' => false,
                'type' => 'web',
                'description' => 'Web (admin + console + kraite.com + syntax) — nginx + php8.5-fpm. No exchange API calls; no step-router consumer.',
            ],
            'eos' => [
                'ip_address' => '204.168.137.153',
                'is_apiable' => true,
                'type' => 'worker',
                'description' => 'Worker — positions / orders / priority',
            ],
            'iris' => [
                'ip_address' => '204.168.138.83',
                'is_apiable' => true,
                'type' => 'worker',
                'description' => 'Worker — positions / orders / priority',
            ],
            'nyx' => [
                'ip_address' => '204.168.129.189',
                'is_apiable' => true,
                'type' => 'worker',
                'description' => 'Worker — positions / orders / priority',
            ],
            'hemera' => [
                'ip_address' => '77.42.68.254',
                'is_apiable' => true,
                'type' => 'worker',
                'description' => 'Worker — positions / orders / priority',
            ],
            'tyche' => [
                'ip_address' => '204.168.135.246',
                'is_apiable' => true,
                'type' => 'worker',
                'description' => 'Worker — indicators + cronjobs (isolated from trading queues)',
            ],
            'palaemon' => [
                'ip_address' => '37.27.192.42',
                'is_apiable' => true,
                'type' => 'worker',
                'description' => 'Worker — positions / orders / priority. Joined 2026-06-12.',
            ],
            'aristaeus' => [
                'ip_address' => '37.27.196.99',
                'is_apiable' => true,
                'type' => 'worker',
                'description' => 'Worker — positions / orders / priority. Joined 2026-06-12.',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fleet Metrics — per-box heartbeat → admin dashboard
    |--------------------------------------------------------------------------
    |
    | Each box writes a live-vitals snapshot to a Redis key every
    | `report_interval_seconds`; the admin dashboard + the system-health
    | watchdog read those keys, joined against the `fleet.servers` registry,
    | to render the fleet and alert on silence.
    |
    | The keys live on a DEDICATED, UNPREFIXED Redis connection (`fleet`,
    | injected by CoreServiceProvider::boot) so every app agrees on the
    | literal key regardless of its own per-app `REDIS_PREFIX`. Without that
    | the ingestion writer and the admin reader would prefix the same logical
    | key differently and never see each other. hyperion (no PHP app) writes
    | the same literal key via raw redis-cli, so the prefix must be empty.
    |
    | Liveness is judged by the embedded `reported_at` age, NOT key existence:
    | the key carries a long `ttl_seconds` (garbage-collection horizon for a
    | decommissioned box), while `stale_after_seconds` (minutes) decides
    | online vs DOWN. The threshold sits comfortably above a clean reboot so a
    | box bouncing through a deploy does not page.
    */
    'fleet_metrics' => [
        'key_prefix' => env('FLEET_METRICS_KEY_PREFIX', 'kraite:fleet:'),
        'redis_database' => (int) env('FLEET_METRICS_REDIS_DB', 2),
        'report_interval_seconds' => (int) env('FLEET_METRICS_REPORT_INTERVAL_SECONDS', 300),
        'ttl_seconds' => (int) env('FLEET_METRICS_TTL_SECONDS', 604800),
        'stale_after_seconds' => (int) env('FLEET_METRICS_STALE_AFTER_SECONDS', 720),

        // Watchdog alert lifecycle. A silent box re-pages once per
        // alert_throttle_seconds — the canonical's default 300s window is
        // shorter than the 7-min check cadence, which would page on every
        // tick while a box is down. A `missing` box whose servers row is
        // younger than provisioning_grace_seconds is skipped entirely:
        // seeded-but-not-yet-warmed is the normal state of a box
        // mid-provision, not an incident. `stale` is never graced.
        'alert_throttle_seconds' => (int) env('FLEET_METRICS_ALERT_THROTTLE_SECONDS', 3600),
        'provisioning_grace_seconds' => (int) env('FLEET_METRICS_PROVISIONING_GRACE_SECONDS', 86400),

        // Reporting identity + queue routing for the heartbeat. The defaults
        // (all null) match a production box, where the OS hostname IS the
        // logical roster name, the default queue connection is the Horizon
        // connection, and a per-host supervisor consumes the `<hostname>`
        // queue. A box where those don't hold — a dev machine whose OS hostname
        // is not `local`, whose default queue connection is `database`, and
        // whose Horizon consumes `default` — overrides them so the identical
        // self-rescheduling heartbeat runs on the already-running local Horizon.
        // `hostname` is the box's logical roster identity, also honoured by
        // Kraite::ip() — without it a box whose OS hostname misses the
        // servers roster falls back to a live ipify lookup on every
        // IP-scoped call (notifications, throttlers, ban diagnostics).
        'hostname' => env('FLEET_METRICS_HOSTNAME'),     // null → gethostname()
        'connection' => env('FLEET_METRICS_CONNECTION'), // null → default queue connection
        'queue' => env('FLEET_METRICS_QUEUE'),           // null → the resolved hostname
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
    | notifications_enabled:    Global notifications master switch. Promoted to
    |                           the kraite singleton (Kraite::notificationsEnabled());
    |                           this is the fallback default. NULL column → this value.
    |
    | NOTE: the former can_trade / can_open_positions env toggles were removed —
    | the master trading kill now lives on the kraite singleton (kraite.can_trade,
    | normally-open) and new-open gating on kraite.allow_opening_positions.
    */
    'slow_query_threshold_ms' => env('SLOW_QUERY_THRESHOLD_MS', 2500),
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

        // Minutes a box may sit in maintenance mode before the health
        // watchdog pages `maintenance_mode_stuck`. Must stay above a
        // full release's cooldown → deploy → warmup span so healthy
        // deploys never trip it (2026-07-02: athena sat down for two
        // days because its warmup never ran `artisan up`).
        'maintenance_stuck_minutes' => (int) env(
            'HEALTH_WATCHDOG_MAINTENANCE_STUCK_MINUTES',
            45
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

            // NOWPayments crypto payment gateway.
            'nowpayments' => [
                'api_key' => env('NOWPAYMENTS_API_KEY'),
                'ipn_secret' => env('NOWPAYMENTS_IPN_SECRET'),
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
        // Phase 3 — regime-scaled risk. Two discrete per-band ramps,
        // applied at position-open only, that STACK with the
        // FragileMarginMultiplier above:
        //   - leverage_ratio scales the determined leverage down so the
        //     liquidation cliff moves further from entry as risk rises.
        //   - count_ratio scales the per-direction position-count cap so
        //     fewer correlated stop-losses can fire together in a drawdown.
        // Calm is always 1.0 (full). Critical (>= block_threshold) blocks
        // opens entirely — its leverage ratio is never observed and its
        // count ratio is 0.0. Both ramps floor the result; leverage also
        // clamps to a minimum of 1x.
        'leverage_ratio' => [
            'elevated' => (float) env('MARKET_REGIME_LEVERAGE_ELEVATED', 0.66),
            'fragile' => (float) env('MARKET_REGIME_LEVERAGE_FRAGILE', 0.50),
        ],
        'count_ratio' => [
            'elevated' => (float) env('MARKET_REGIME_COUNT_ELEVATED', 0.75),
            'fragile' => (float) env('MARKET_REGIME_COUNT_FRAGILE', 0.50),
        ],
        // Phase 2.1C — directional crowding multiplier. Downscales margin
        // on the side of the book that already carries >= `threshold`
        // share of the total notional exposure. Empty side stays NEUTRAL
        // (1.0×) until backtest evidence justifies an upscale.
        'crowding' => [
            'threshold' => (float) env('MARKET_REGIME_CROWDING_THRESHOLD', 0.70),
            'max_reduction_pct' => (float) env('MARKET_REGIME_CROWDING_MAX_REDUCTION', 50),
        ],
        // Fast cascade-in-progress detector. Distinct from BSCS (slow,
        // hourly) — runs every minute on 15m klines for BTC + 4 alts to
        // catch cliffs that the BSCS hourly cron would only see at the
        // next :50 tick. When any rule fires, arms the SHARED
        // `bscs_cooldown_until` column for `cooldown_hours` (default
        // 1h — a short pause to ride out an in-progress cascade, then
        // resume; the slow BSCS-score cooldown below stays 24h).
        // Notification: `market_shock_circuit_breaker` — fires once per
        // fresh arming, silent re-arm while a cooldown is already active
        // to avoid double-pinging.
        'shock' => [
            'thresholds' => [
                'btc_15m_pct' => (float) env('MARKET_SHOCK_BTC_15M_PCT', -3.0),
                'btc_1h_pct' => (float) env('MARKET_SHOCK_BTC_1H_PCT', -5.0),
                'alt_basket_1h_pct' => (float) env('MARKET_SHOCK_ALT_BASKET_1H_PCT', -7.0),
                'corr_1h' => (float) env('MARKET_SHOCK_CORR_1H', 0.85),
                'magnitude_pct' => (float) env('MARKET_SHOCK_MAGNITUDE_PCT', 3.0),
            ],
            'cooldown_hours' => (int) env('MARKET_SHOCK_COOLDOWN_HOURS', 1),
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

    /*
    |--------------------------------------------------------------------------
    | Local Development — Restricted Symbol Set
    |--------------------------------------------------------------------------
    |
    |   When APP_ENV=local, only these tokens are upserted by
    |   refresh-exchange-symbols. Keeps API calls and DB volume
    |   manageable on a dev machine. BTC is mandatory for
    |   correlation calculations.
    |
    |   Production (APP_ENV=production) ignores this — full
    |   symbol universe is fetched.
    */
    'local_symbols' => [
        // Original 21
        'BTC', 'ETH', 'SOL', 'BNB', 'XRP',
        'DOGE', 'ADA', 'AVAX', 'DOT', 'LINK',
        'AAVE', 'UNI', 'ATOM', 'APT', 'NEAR',
        'WIF', 'PEPE', '1000SHIB', 'FLOKI', 'BONK',
        'CHZ',
        // Additional 50
        'LTC', 'ETC', 'FIL', 'ARB', 'OP',
        'MATIC', 'SAND', 'MANA', 'GRT', 'FTM',
        'ALGO', 'ICP', 'TRX', 'HBAR', 'VET',
        'IMX', 'INJ', 'SEI', 'SUI', 'TIA',
        'JUP', 'RENDER', 'FET', 'ONDO', 'PENDLE',
        'STX', 'MKR', 'LDO', 'CRV', 'COMP',
        'SNX', 'RUNE', 'THETA', 'GALA', 'AXS',
        'ENS', 'DYDX', 'BLUR', 'JTO', 'PYTH',
        'W', 'ENA', 'ETHFI', 'STRK', 'ZRO',
        'TAO', 'KAS', 'ORDI', '1000SATS', 'WLD',
        // Widened +50 (2026-06-08) — broader candidate pool on localhost
        // so token discovery has more LONG/SHORT setups to choose from.
        'TON', 'BCH', 'XLM', 'EOS', 'NEO',
        'IOTA', 'KAVA', 'FLOW', 'EGLD', 'ROSE',
        'ZIL', 'ENJ', 'ANKR', 'ZEC', 'DASH',
        'GMX', 'LRC', 'MASK', 'APE', 'GMT',
        'JASMY', '1INCH', 'SUSHI', 'BAND', 'API3',
        'ARKM', 'AEVO', 'ALT', 'MANTA', 'DYM',
        'PIXEL', 'PORTAL', 'XAI', 'ACE', 'NOT',
        'IO', 'DOGS', 'TURBO', 'NEIRO', 'POPCAT',
        'MEW', 'BOME', 'MEME', 'TWT', 'MAGIC',
        'ID', 'SSV', 'TRB', 'UMA', 'YGG',
    ],

    /*
    |--------------------------------------------------------------------------
    | Horizon — Supervisor Topology (single source of truth)
    |--------------------------------------------------------------------------
    |
    | Read at boot by `CoreServiceProvider::syncHorizonEnvironmentsFromKraiteConfig()`
    | — the transformer picks the block matching `HORIZON_ENV` and writes it
    | into `config('horizon.environments.<env>')` so a Horizon supervisor
    | started under that env consumes exactly the queues declared here.
    |
    | Why this lives in the package and not just in the ingestion project
    | config: web apps (admin, console, kraite.com) load the package config
    | by default (no project-level `config/kraite.php`). If the workers
    | block were only in ingestion, admin's Horizon transformer would see
    | nothing for `HORIZON_ENV=pheme` and the supervisor would boot with
    | an empty queue list. Single source of truth here keeps every app
    | aware of the full fleet topology.
    |
    | The ingestion project's own `config/kraite.php` mirrors this block —
    | small duplication kept on purpose so ingestion never depends on the
    | package config landing first. The `kraite:verify-fleet-topology` drift
    | gate at deploy step 10 asserts the two stay aligned.
    */
    'horizon' => [
        'defaults' => [
            'connection' => 'redis',
            'timeout' => 0,
            'sleep' => 1,
            'tries' => 5,
            'backoff' => 10,
            'memory' => 256,
        ],
        'workers' => [
            'local' => [
                'positions' => ['processes' => 2],
                'orders' => ['processes' => 5],
                'priority' => ['processes' => 2],
                'cronjobs' => ['processes' => 2],
                'indicators' => ['processes' => 5],
                'user-data-stream' => ['processes' => 1],
                'local' => ['processes' => 1],
            ],
            // Ingestion box (scheduler + dispatch-daemon + Binance WS
            // daemons). Also a second indicators consumer: athena's public
            // IP joins tyche's as a candidate for the kline/indicator lane
            // so StepRouter spreads the per-IP Bybit burst across two IPs
            // (cuts retCode 10006) and can rotate off a rate-limited tyche
            // IP. Sized at 10 vs tyche's 20 to protect the scheduler.
            'athena' => [
                'user-data-stream' => ['processes' => 5],
                'indicators' => ['processes' => 10],
                'athena' => ['processes' => 1],
            ],
            'pheme' => [
                // Logical `web` composes to physical `pheme-web` via the
                // {hostname}-{logical} convention — naming it `pheme-web`
                // here would double-prefix to `pheme-pheme-web`.
                'web' => ['processes' => 2],
                'pheme' => ['processes' => 1],
            ],
            'eos' => [
                'positions' => ['processes' => 5],
                'orders' => ['processes' => 8],
                'priority' => ['processes' => 3],
                'eos' => ['processes' => 1],
            ],
            'iris' => [
                'positions' => ['processes' => 5],
                'orders' => ['processes' => 8],
                'priority' => ['processes' => 3],
                'iris' => ['processes' => 1],
            ],
            'nyx' => [
                'positions' => ['processes' => 5],
                'orders' => ['processes' => 8],
                'priority' => ['processes' => 3],
                'nyx' => ['processes' => 1],
            ],
            'hemera' => [
                'positions' => ['processes' => 5],
                'orders' => ['processes' => 8],
                'priority' => ['processes' => 3],
                'hemera' => ['processes' => 1],
            ],
            'tyche' => [
                'indicators' => ['processes' => 20],
                'cronjobs' => ['processes' => 20],
                'priority' => ['processes' => 5],
                'tyche' => ['processes' => 5],
            ],
        ],
    ],
];
