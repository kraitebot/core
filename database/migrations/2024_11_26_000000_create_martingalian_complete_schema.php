<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // account_balance_history table
        Schema::create('account_balance_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->decimal('total_wallet_balance', 20, 5)->nullable();
            $table->decimal('total_unrealized_profit', 20, 5)->nullable();
            $table->decimal('total_maintenance_margin', 20, 5)->nullable();
            $table->decimal('total_margin_balance', 20, 5)->nullable();
            $table->timestamps();

            $table->index(['account_id', 'created_at'], 'ab_hist_account_created_idx');
        });

        // account_history table
        Schema::create('account_history', function (Blueprint $table) {
            $table->id();
            $table->string('account_id')->nullable()->index();
            $table->string('event_type');
            $table->string('event_reason')->nullable();
            $table->json('balances')->nullable();
            $table->json('positions')->nullable();
            $table->string('transaction_time')->nullable();
            $table->string('event_time')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();
        });

        // accounts table
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->string('name')->nullable(false)->comment('Account name for user identification (e.g., "Main Trading Account", "Backup Account")');
            $table->unsignedBigInteger('user_id')->comment('The related user id');
            $table->unsignedBigInteger('api_system_id')->comment('The related api system id');
            $table->unsignedBigInteger('trade_configuration_id')->comment('The related trade configuration id');
            $table->unsignedBigInteger('portfolio_quote_id')->nullable()->comment('The related portfolio quote, to obtain the respective balance and work with that portfolio');
            $table->unsignedBigInteger('trading_quote_id')->nullable()->comment('The related coin quote, to open positions and be rich');
            $table->decimal('margin', 20, 8)->nullable()->comment('If filled, it will be used, instead of the trade configuration default margin percentage');
            $table->boolean('can_trade')->default(true)->comment('If true then it will be used to dispatch positions for this account');
            $table->boolean('is_active')->default(true);
            $table->string('disabled_reason')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->decimal('market_order_margin_percentage_long', 5, 2)->default(0.42);
            $table->decimal('market_order_margin_percentage_short', 5, 2)->default(0.37);
            $table->decimal('profit_percentage', 6, 3)->default(0.360)->comment('The profit percentage');
            $table->decimal('margin_ratio_threshold_to_notify', 5, 2)->default(1.50)->comment('Minimum margin ratio to start notifying the account admin');
            $table->unsignedTinyInteger('total_limit_orders_filled_to_notify')->default(0)->comment('After how many limit orders should we notify the account user');
            $table->decimal('stop_market_initial_percentage', 5, 2)->default(2.50);
            $table->unsignedInteger('total_positions_short')->default(1)->comment('Max active positions SHORT');
            $table->unsignedInteger('total_positions_long')->default(1)->comment('Max active positions LONG');
            $table->unsignedInteger('position_leverage_short')->default(15)->comment('The max leverage that the position SHORT can use');
            $table->unsignedInteger('position_leverage_long')->default(20)->comment('The max leverage that the position LONG can use');
            $table->longText('binance_api_key')->nullable();
            $table->longText('binance_api_secret')->nullable();
            $table->longText('bybit_api_key')->nullable();
            $table->longText('bybit_api_secret')->nullable();
            $table->longText('kraken_api_key')->nullable();
            $table->longText('kraken_private_key')->nullable();
            $table->unsignedBigInteger('last_report_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('can_trade', 'idx_accounts_can_trade');
            $table->index(['user_id', 'can_trade'], 'idx_accounts_user_can_trade');
            $table->unique(['user_id', 'name'], 'idx_accounts_user_name_unique');
        });

        // api_request_logs table
        Schema::create('api_request_logs', function (Blueprint $table) {
            $table->id();
            $table->string('relatable_type')->nullable();
            $table->unsignedBigInteger('relatable_id')->nullable();
            $table->unsignedBigInteger('api_system_id');
            $table->unsignedBigInteger('account_id')->nullable()->index();
            $table->integer('http_response_code')->nullable();
            $table->json('debug_data')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('duration')->default(0);
            $table->string('path')->nullable();
            $table->longText('payload')->nullable();
            $table->string('http_method')->nullable();
            $table->longText('http_headers_sent')->nullable();
            $table->longText('response')->nullable();
            $table->longText('http_headers_returned')->nullable();
            $table->string('hostname')->nullable();
            $table->longText('error_message')->nullable();
            $table->timestamps();

            $table->index('created_at', 'idx_api_request_logs_created_at');
            $table->index(['relatable_type', 'relatable_id', 'created_at'], 'api_req_logs_rel_idx');
            $table->index(['created_at', 'id'], 'idx_p_arl_created_id');
        });

        // api_snapshots table
        Schema::create('api_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('responsable_type');
            $table->unsignedBigInteger('responsable_id');
            $table->string('canonical');
            $table->json('api_response')->nullable();
            $table->timestamps();

            $table->unique(['responsable_type', 'responsable_id', 'canonical'], 'unique_api_snapshot_per_canonical');
        });

        // api_systems table
        Schema::create('api_systems', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_exchange')->default(true);
            $table->string('name');
            $table->string('logo_url')->nullable();
            $table->unsignedInteger('recvwindow_margin')->default(10000)->comment('The miliseconds margin so we dont get errors due to server time vs exchange time desynchronizations');
            $table->string('canonical')->unique();
            $table->string('websocket_class')->nullable();
            $table->timestamps();

            $table->index('is_exchange', 'idx_api_systems_is_exchange');
        });

        // base_asset_mappers table
        Schema::create('base_asset_mappers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('api_system_id');
            $table->string('symbol_token');
            $table->string('exchange_token');
            $table->timestamps();

            $table->index(['api_system_id', 'symbol_token'], 'idx_api_symbol_token');
        });

        // early_access table
        Schema::create('early_access', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->timestamps();
        });

        // exchange_symbols table (must come before candles due to FK)
        Schema::create('exchange_symbols', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('symbol_id');
            $table->unsignedBigInteger('quote_id');
            $table->unsignedInteger('api_system_id');
            $table->boolean('is_manually_enabled')->default(1)->comment('Manual admin override: 1=enabled (default), 0=disabled');
            $table->boolean('has_no_indicator_data')->default(false)->comment('True when TAAPI returns no data (3+ failures)');
            $table->boolean('has_price_trend_misalignment')->default(false)->comment('True when price movement contradicts concluded direction');
            $table->boolean('has_early_direction_change')->default(false)->comment('True when direction changes before minimum allowed timeframe index');
            $table->boolean('has_invalid_indicator_direction')->default(false)->comment('True when all timeframes exhausted without concluding a direction');
            $table->json('api_statuses')->nullable()->comment('JSON object tracking API call statuses: cmc_api_called, taapi_verified');
            $table->string('direction')->nullable()->comment('The exchange symbol open position direction (LONG, SHORT)');
            $table->decimal('percentage_gap_long', 5, 2)->default(8.50);
            $table->decimal('percentage_gap_short', 5, 2)->default(9.50);
            $table->unsignedInteger('price_precision');
            $table->unsignedInteger('quantity_precision');
            $table->decimal('min_notional', 20, 8)->nullable()->comment('The minimum position size that can be opened (quantity x price at the moment of the position opening)');
            $table->unsignedBigInteger('delivery_ts_ms')->nullable();
            $table->dateTime('delivery_at')->nullable();
            $table->decimal('tick_size', 20, 8);
            $table->decimal('min_price', 20, 8)->nullable()->comment('Min price for this exchange symbol');
            $table->decimal('max_price', 20, 8)->nullable()->comment('Max price for this exchange symbol');
            $table->longText('symbol_information')->nullable();
            $table->unsignedInteger('total_limit_orders')->default(4)->comment('Total limit orders, for the martingale calculation');
            $table->longText('leverage_brackets')->nullable();
            $table->text('indicators_values')->nullable();
            $table->json('limit_quantity_multipliers')->nullable();
            $table->decimal('disable_on_price_spike_percentage', 4, 2)->default(15.00);
            $table->unsignedTinyInteger('price_spike_cooldown_hours')->default(72);
            $table->string('indicators_timeframe')->nullable();
            $table->timestamp('indicators_synced_at')->nullable()->comment('When indicators were last fetched/synced');
            $table->json('btc_correlation_pearson')->nullable();
            $table->json('btc_correlation_spearman')->nullable();
            $table->json('btc_correlation_rolling')->nullable();
            $table->json('btc_elasticity_long')->nullable();
            $table->json('btc_elasticity_short')->nullable();
            $table->timestamp('tradeable_at')->nullable()->comment('Cooldown timestamp so a symbol cannot be tradeable until a certain moment');
            $table->boolean('overlaps_with_binance')->nullable()->index()->comment('True if this token exists on Binance (for TAAPI indicator compatibility)');
            $table->boolean('is_marked_for_delisting')->default(false)->index()->comment('True when Binance symbol is delisted, cascades to other exchanges');
            $table->timestamps();

            $table->unique(['symbol_id', 'api_system_id', 'quote_id'], 'exchange_symbols_symbol_id_api_system_id_quote_id_unique');
            $table->index('has_no_indicator_data', 'idx_exchange_symbols_has_no_indicator_data');
            $table->index('has_price_trend_misalignment', 'idx_exchange_symbols_has_price_trend_misalignment');
            $table->index('has_early_direction_change', 'idx_exchange_symbols_has_early_direction_change');
            $table->index('has_invalid_indicator_direction', 'idx_exchange_symbols_has_invalid_indicator_direction');
            $table->index('direction', 'idx_exchange_symbols_direction');
        });

        // candles table (requires exchange_symbols to exist first)
        Schema::create('candles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('exchange_symbol_id');
            $table->string('timeframe', 16);
            $table->decimal('open', 36, 18);
            $table->decimal('high', 36, 18);
            $table->decimal('low', 36, 18);
            $table->decimal('close', 36, 18);
            $table->decimal('volume', 36, 18)->default(0);
            $table->unsignedBigInteger('timestamp')->index();
            $table->dateTime('candle_time_utc')->nullable()->index();
            $table->dateTime('candle_time_local')->nullable()->index();
            $table->timestamps();

            $table->unique(['exchange_symbol_id', 'timeframe', 'timestamp'], 'candles_symbol_timeframe_timestamp_unique');
            $table->foreign('exchange_symbol_id')->references('id')->on('exchange_symbols');
        });

        // forbidden_hostnames table
        Schema::create('forbidden_hostnames', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_system_id')->constrained('api_systems');
            $table->unsignedBigInteger('account_id')->nullable();
            $table->string('ip_address', 45);
            $table->string('type', 32)->default('ip_not_whitelisted')->comment('Type: ip_not_whitelisted, ip_rate_limited, ip_banned, account_blocked');
            $table->timestamp('forbidden_until')->nullable()->comment('When temporary ban expires, null for permanent/user-fixable');
            $table->string('error_code', 32)->nullable()->comment('Original error code from exchange (e.g., -2015, 10010)');
            $table->string('error_message')->nullable()->comment('Original error message from exchange');
            $table->timestamps();

            $table->foreign('account_id')->references('id')->on('accounts')->onDelete('cascade');
            $table->index(['api_system_id', 'ip_address', 'type'], 'fh_system_ip_type_idx');
            $table->index(['account_id', 'type'], 'fh_account_type_idx');
        });

        // fundings table
        Schema::create('fundings', function (Blueprint $table) {
            $table->id();
            $table->string('type')->index();
            $table->decimal('amount', 10, 2);
            $table->timestamp('date_value')->index();
            $table->timestamps();

            $table->index(['type', 'date_value'], 'idx_funding_type_date');
            $table->index(['type', 'created_at'], 'idx_funding_type_created');
        });

        // indicator_histories table
        Schema::create('indicator_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('exchange_symbol_id')->index();
            $table->unsignedBigInteger('indicator_id')->index();
            $table->string('taapi_construct_id')->nullable();
            $table->string('timeframe')->index();
            $table->string('timestamp')->index();
            $table->json('data');
            $table->text('conclusion')->nullable();
            $table->timestamps();

            $table->unique(['exchange_symbol_id', 'indicator_id', 'timeframe', 'timestamp'], 'idx_unique_indicator_history');
            $table->index(['exchange_symbol_id', 'indicator_id', 'timeframe', 'timestamp'], 'idx_indhist_es_i_tf_ts');
            $table->index(['indicator_id', 'timeframe', 'exchange_symbol_id', 'timestamp'], 'idx_indicator_histories_itst');
        });

        // indicators table
        Schema::create('indicators', function (Blueprint $table) {
            $table->id();
            $table->string('type')->default('refresh-data')->comment('The indicator group class. E.g.: refresh-data means the indicator will be used to query exchange symbols indicator data cronjobs');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_computed')->default(false);
            $table->string('canonical')->unique()->comment('Internal id that will be set on the indicator class for further results mapping');
            $table->json('parameters')->nullable()->comment('The parameters that will be passed to taapi. Just indicator parameters, not secret neither exchange parameters');
            $table->string('class')->comment('The indicator class that will be used to instance and use the indicator');
            $table->timestamps();

            $table->index('is_active', 'idx_indicators_is_active');
            $table->index('type', 'idx_indicators_type');
            $table->index(['type', 'is_active'], 'idx_indicators_type_active');
        });

        // martingalian table
        Schema::create('martingalian', function (Blueprint $table) {
            $table->id();
            $table->longText('binance_api_key')->nullable();
            $table->longText('binance_api_secret')->nullable();
            $table->longText('bybit_api_key')->nullable();
            $table->longText('bybit_api_secret')->nullable();
            $table->longText('kraken_api_key')->nullable();
            $table->longText('kraken_private_key')->nullable();
            $table->longText('coinmarketcap_api_key')->nullable();
            $table->longText('taapi_secret')->nullable();
            $table->json('notification_channels')->nullable();
            $table->longText('admin_pushover_user_key')->nullable();
            $table->longText('admin_pushover_application_key')->nullable();
            $table->string('email')->nullable();
            $table->boolean('allow_opening_positions')->default(false);
            $table->boolean('is_cooling_down')->default(true)->comment('When true, scheduler stops dispatching steps for safe deployment');
            $table->timestamps();
        });

        // notifications table
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->string('canonical')->unique()->comment('Base message canonical identifier (e.g., ip_not_whitelisted)');
            $table->string('title')->comment('Human-readable notification title');
            $table->text('description')->nullable()->comment('Description of when this notification is sent');
            $table->text('detailed_description')->nullable()->comment('Comprehensive technical details: HTTP codes, vendor error codes, error messages, and triggering conditions from exchange APIs');
            $table->text('usage_reference')->nullable()->comment('Where this notification is used (e.g., "Used in ApplicationLogObserver, method handleException")');
            $table->boolean('verified')->default(false)->comment('Whether this notification has been tested and verified with real-world usage');
            $table->string('default_severity')->nullable()->comment('Default severity level (Critical, High, Medium, Info)');
            $table->unsignedInteger('cache_duration')->default(600)->comment('Cache duration in seconds for throttling (600 = 10 minutes default, 0 = no throttle, >0 = throttle window)');
            $table->json('cache_key')->nullable()->comment('Array of required cache key parameters (e.g., ["api_system", "account"]) used to build unique cache key for throttling');
            $table->timestamps();

            $table->index('canonical', 'notifications_canonical_index');
        });

        // notification_logs table - Legal audit trail for all sent notifications
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->comment('Unique identifier for backend reference');
            $table->unsignedBigInteger('notification_id')->nullable()->comment('FK to notifications table - which notification definition was used');
            $table->string('canonical')->comment('Notification canonical used (e.g., ip_not_whitelisted)');
            $table->unsignedBigInteger('user_id')->nullable()->comment('FK to users table - which user received this notification (null for admin virtual user)');
            $table->nullableMorphs('relatable', 'notification_logs_relatable_index');
            $table->string('channel')->comment('Delivery channel: mail, pushover');
            $table->string('recipient')->comment('Email address or Pushover key');
            $table->string('message_id')->nullable()->comment('Gateway message ID for tracking (Zeptomail request_id, Pushover receipt)');
            $table->timestamp('sent_at')->comment('When notification was sent');
            $table->timestamp('opened_at')->nullable()->comment('When email was opened by recipient (mail channel only)');
            $table->timestamp('soft_bounced_at')->nullable()->comment('When email soft bounced (mail channel only)');
            $table->timestamp('hard_bounced_at')->nullable()->comment('When email hard bounced (mail channel only)');
            $table->string('status')->default('delivered')->comment('Status: delivered, opened, soft bounced, hard bounced');
            $table->json('http_headers_sent')->nullable()->comment('HTTP headers sent to gateway');
            $table->json('http_headers_received')->nullable()->comment('HTTP headers received from gateway');
            $table->json('gateway_response')->nullable()->comment('Gateway API response');
            $table->longText('content_dump')->nullable()->comment('Full notification content for legal audit');
            $table->longText('raw_email_content')->nullable()->comment('Raw email HTML/text content for mail viewers (mail channel only)');
            $table->text('error_message')->nullable()->comment('Error message if delivery failed');
            $table->timestamps();

            $table->index('notification_id', 'notification_logs_notification_id_index');
            $table->index('canonical', 'notification_logs_canonical_index');
            $table->index('channel', 'notification_logs_channel_index');
            $table->index('status', 'notification_logs_status_index');
            $table->index('sent_at', 'notification_logs_sent_at_index');
            $table->index('message_id', 'notification_logs_message_id_index');
        });

        // order_history table
        Schema::create('order_history', function (Blueprint $table) {
            $table->id();
            $table->string('order_id')->nullable();
            $table->string('orderId')->nullable();
            $table->string('symbol')->nullable();
            $table->string('status')->nullable();
            $table->string('clientOrderId')->nullable();
            $table->string('price')->nullable();
            $table->string('avgPrice')->nullable();
            $table->string('origQty')->nullable();
            $table->string('executedQty')->nullable();
            $table->string('cumQuote')->nullable();
            $table->string('lastFilledPrice')->nullable();
            $table->string('lastFilledQty')->nullable();
            $table->string('timeInForce')->nullable();
            $table->string('type')->nullable();
            $table->string('reduceOnly')->nullable();
            $table->string('closePosition')->nullable();
            $table->string('side')->nullable();
            $table->string('positionSide')->nullable();
            $table->string('stopPrice')->nullable();
            $table->string('workingType')->nullable();
            $table->string('priceProtect')->nullable();
            $table->string('origType')->nullable();
            $table->string('priceMatch')->nullable();
            $table->string('selfTradePreventionMode')->nullable();
            $table->string('goodTillDate')->nullable();
            $table->string('time')->nullable();
            $table->string('updateTime')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'created_at'], 'order_hist_ord_idx');
        });

        // orders table
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('position_id');
            $table->uuid('uuid');
            $table->string('client_order_id')->unique()->comment('Client order ID for syncronization issues');
            $table->string('type')->comment('PROFIT, MARKET, LIMIT, CANCEL-MARKET');
            $table->string('reference_status')->nullable()->comment('The referenced order status, only progresses manually');
            $table->string('status')->default('NEW')->comment('The order status (filled, cancelled, new, etc), progresses via apiSync');
            $table->string('side')->comment('BUY or SELL - To open a short, or a long');
            $table->string('position_side')->nullable()->comment('Used to define the type of position direction, for the hybrid hedging strategy');
            $table->string('exchange_order_id')->nullable()->comment('The exchange system order id');
            $table->decimal('reference_quantity', 20, 8)->nullable()->comment('The order refered initial or filled quantity, which should be the right value');
            $table->decimal('quantity', 20, 8)->nullable()->comment('The order initial or filled quantity, depending on the order status');
            $table->decimal('reference_price', 20, 8)->nullable()->comment('The order refered initial or filled price, which should be the right value');
            $table->decimal('price', 20, 8)->nullable()->comment('The order initial or average price, depending on the status');
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('filled_at')->nullable();
            $table->timestamps();

            $table->index(['position_id', 'status', 'created_at'], 'idx_orders_pos_status_created');
            $table->index('created_at', 'idx_orders_created_at');
        });

        // Create orders table indexes with length limits
        DB::statement('CREATE INDEX ord_pos_side_type_id_idx ON orders (position_id, position_side(8), type(16), id)');
        DB::statement('CREATE INDEX ord_pos_side_status_id_idx ON orders (position_id, position_side(8), status(16), id)');
        DB::statement('CREATE INDEX ord_limit_qty_idx ON orders (position_id, position_side(8), type(16), status(16), quantity)');
        DB::statement('CREATE INDEX ord_exchange_order_id_idx ON orders (exchange_order_id(64))');

        // positions table
        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->string('parsed_trading_pair')->nullable()->comment('The parsed trading pair, compatible with the exchange trading pair convention');
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('exchange_symbol_id')->nullable();
            $table->string('status')->default('new')->comment('The position status: new (never synced/syncing), active (totally synced), closed (synced, but no longer active), cancelled (there was an error or was compulsively cancelled)');
            $table->string('direction')->nullable()->comment('The position direction: LONG, or SHORT');
            $table->uuid('uuid');
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('watched_at')->nullable()->comment('Since when a position is being watched');
            $table->timestamp('closed_at')->nullable();
            $table->string('hedge_step')->nullable();
            $table->boolean('was_waped')->default(false)->comment('If this position received a WAP recalculation');
            $table->timestamp('waped_at')->nullable()->comment('When was the last time this position was waped');
            $table->string('waped_by')->nullable();
            $table->boolean('was_fast_traded')->default(false)->comment('Indicates if this position was considered fast traded (less than the fast traded duration)');
            $table->string('closed_by')->nullable()->comment('Who was the source (user data stream, or watcher) to apply the closing action on this position');
            $table->unsignedInteger('total_limit_orders')->nullable()->comment('Total position limit orders');
            $table->decimal('opening_price', 20, 8)->nullable()->comment('The current exchange symbol mark price when the position was opened');
            $table->decimal('margin', 20, 8)->nullable()->comment('The position margin (meaning the portfolio amount without leverage)');
            $table->decimal('quantity', 20, 8)->nullable()->comment('The current total position quantity (except hedge position quantity)');
            $table->decimal('first_profit_price', 20, 8)->nullable()->comment('The first profit price, to be used for the alpha path calculation');
            $table->decimal('closing_price', 20, 8)->nullable()->comment('The last profit price');
            $table->json('indicators_values')->nullable()->comment('The indicator result at the moment that the position was created');
            $table->string('indicators_timeframe')->nullable()->comment('The indicator timeframe when the position was created');
            $table->unsignedTinyInteger('leverage')->nullable();
            $table->decimal('profit_percentage', 6, 3)->nullable()->comment('The profit percentage obtained from the trade configuration');
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'closed_at'], 'pos_account_closed_idx');
            $table->index('closed_at', 'pos_closed_idx');
            $table->index(['status', 'waped_at', 'account_id'], 'idx_positions_status_waped_account');
            $table->index('status', 'idx_positions_status');
            $table->index(['account_id', 'status'], 'idx_positions_account_status');
            $table->index('direction', 'idx_positions_direction');
        });

        // quotes table
        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->string('canonical')->unique();
            $table->string('name');
            $table->timestamps();
        });

        // servers table
        Schema::create('servers', function (Blueprint $table) {
            $table->id();
            $table->string('hostname')->unique()->comment('Server hostname identifier');
            $table->string('ip_address')->comment('Server IP address');
            $table->boolean('is_apiable')->default(false)->comment('Whether this server makes API calls to exchanges');
            $table->boolean('needs_whitelisting')->default(false)->comment('Whether this server IP needs to be whitelisted on trading platforms');
            $table->string('own_queue_name')->nullable()->comment('Unique queue name for sending jobs directly to this server');
            $table->text('description')->nullable()->comment('Description of what this server is used for');
            $table->string('type')->default('ingestion')->comment('Server type (ingestion, worker, etc)');
            $table->string('secret')->nullable()->comment('Secret token for health check authentication');
            $table->timestamps();

            $table->index('is_apiable', 'idx_servers_is_apiable');
            $table->index('hostname', 'idx_servers_hostname');
        });

        // slow_queries table
        Schema::create('slow_queries', function (Blueprint $table) {
            $table->id();
            $table->string('tick_id')->nullable()->index('slow_queries_tick_id_index');
            $table->string('connection', 64)->index('slow_queries_connection_index');
            $table->unsignedInteger('time_ms')->index('slow_queries_time_ms_index');
            $table->mediumText('sql');
            $table->mediumText('sql_full')->nullable();
            $table->json('bindings')->nullable();
            $table->timestamps();

            $table->index(['created_at', 'time_ms'], 'slow_queries_created_at_time_ms_index');
        });

        // NOTE: steps, steps_dispatcher, steps_dispatcher_ticks tables are now
        // created by the brunocfalcao/step-dispatcher package migration

        // symbols table
        Schema::create('symbols', function (Blueprint $table) {
            $table->id();
            $table->string('token')->nullable();
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->string('site_url')->nullable();
            $table->string('image_url')->nullable();
            $table->unsignedInteger('cmc_id')->nullable();
            $table->unsignedInteger('cmc_ranking')->nullable();
            $table->boolean('is_stable_coin')->default(false);
            $table->string('cmc_category')->nullable();
            $table->timestamps();

            $table->index('token', 'symbols_token_index');
        });

        // token_mappers table - Maps token naming conventions between exchanges
        // Used to translate Binance token names (used for TAAPI indicators) to other exchange token names
        Schema::create('token_mappers', function (Blueprint $table) {
            $table->id();
            $table->string('binance_token', 50)->comment('The Binance token name (reference for TAAPI indicators)');
            $table->string('other_token', 50)->comment('The equivalent token name on the other exchange');
            $table->unsignedInteger('other_api_system_id')->comment('The api_system_id of the other exchange');
            $table->timestamps();

            $table->unique(['binance_token', 'other_api_system_id'], 'token_mappers_binance_other_unique');
            $table->index('other_api_system_id', 'token_mappers_other_api_system_id_index');
            $table->index(['other_token', 'other_api_system_id'], 'token_mappers_other_token_system_index');
        });

        // trade_configuration table
        Schema::create('trade_configuration', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_default')->default(false);
            $table->string('canonical')->unique();
            $table->string('description')->nullable();
            $table->unsignedInteger('least_timeframe_index_to_change_indicator')->default(1)->comment('Minimum array key index on the timeframe array to accept a direction change');
            $table->unsignedInteger('fast_trade_position_duration_seconds')->default(600)->comment('Total seconds that a position had since opened_at to closed_at, to be considered fast tracked. E.g.: 60 means, only positions that were opened and closed in less than 60 seconds');
            $table->unsignedInteger('fast_trade_position_closed_age_seconds')->default(3600)->comment('Total seconds after a position as been closed to consider a position as fast tracked. E.g: 3600 means only take in consideration for possible fast track positions that were closed no more than 1h ago');
            $table->boolean('disable_exchange_symbol_from_negative_pnl_position')->default(false)->comment('If a position is closed with a negative PnL, then the exchange symbol is immediately disabled for trading');
            $table->json('indicator_timeframes')->nullable()->comment('Taapi timeframes considered for the trade configuration');
            $table->decimal('min_account_balance', 20, 8)->default(100)->comment('Minimum account balance required to dispatch new positions');
            $table->timestamps();
        });

        // Modify users table to add custom columns
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable()->after('password');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
            $table->text('pushover_key')->nullable()->after('two_factor_confirmed_at');
            $table->json('notification_channels')->nullable()->after('pushover_key');
            $table->json('behaviours')->nullable()->after('notification_channels')->comment('User behavior flags (e.g., should_announce_bounced_email)');
            $table->timestamp('previous_logged_in_at')->nullable()->after('remember_token');
            $table->timestamp('last_logged_in_at')->nullable()->after('previous_logged_in_at');
            $table->boolean('is_active')->default(false)->after('last_logged_in_at');
            $table->boolean('can_trade')->default(true)->after('is_active');
            $table->boolean('is_admin')->default(false)->after('can_trade');
            $table->uuid('current_connectivity_test_uuid')->nullable()->after('is_admin')->comment('Block UUID of currently running connectivity test');

            $table->index('is_active', 'idx_users_is_active');
            $table->index('can_trade', 'idx_users_can_trade');
            $table->index('is_admin', 'idx_users_is_admin');
        });

        // model_logs table
        Schema::create('model_logs', function (Blueprint $table) {
            $table->id();

            // SOURCE MODEL: The model being changed (automatic)
            $table->morphs('loggable'); // loggable_type, loggable_id

            // RELATABLE MODEL: The model that triggered this change (manual/optional)
            $table->nullableMorphs('relatable'); // relatable_type, relatable_id

            // Event information
            $table->string('event_type'); // 'attribute_created', 'attribute_changed', 'job_failed', etc.
            $table->string('attribute_name')->nullable(); // For attribute changes

            // Human-readable message
            $table->longText('message')->nullable(); // "Attribute 'status' changed from 'pending' to 'completed'"

            // Values (stored as LONGTEXT for flexibility - supports raw values and large arrays)
            $table->longText('previous_value')->nullable(); // The old value
            $table->longText('new_value')->nullable();      // The new value

            // Metadata for custom manual logs
            $table->json('metadata')->nullable();

            $table->timestamps();

            // Indexes (morphs/nullableMorphs already create loggable/relatable indexes)
            $table->index('event_type');
            $table->index('created_at');
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {

        Schema::dropIfExists('model_logs');
        Schema::dropIfExists('slow_queries');
        Schema::dropIfExists('steps');
        Schema::dropIfExists('steps_dispatcher_ticks');
        Schema::dropIfExists('steps_dispatcher');
        Schema::dropIfExists('candles');
        Schema::dropIfExists('indicator_histories');
        Schema::dropIfExists('indicators');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('order_history');
        Schema::dropIfExists('positions');
        Schema::dropIfExists('exchange_symbols');
        Schema::dropIfExists('account_balance_history');
        Schema::dropIfExists('account_history');
        Schema::dropIfExists('accounts');
        Schema::dropIfExists('api_request_logs');
        Schema::dropIfExists('api_snapshots');
        Schema::dropIfExists('base_asset_mappers');
        Schema::dropIfExists('api_systems');
        Schema::dropIfExists('servers');
        Schema::dropIfExists('forbidden_hostnames');
        Schema::dropIfExists('notification_logs');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('martingalian');
        Schema::dropIfExists('trade_configuration');
        Schema::dropIfExists('quotes');
        Schema::dropIfExists('token_mappers');
        Schema::dropIfExists('symbols');
        Schema::dropIfExists('fundings');
        Schema::dropIfExists('early_access');
    }
};
