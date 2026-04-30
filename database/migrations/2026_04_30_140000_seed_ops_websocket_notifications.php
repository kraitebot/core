<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seeds two ops-level notification canonicals for visibility into
 * the live price stream's health:
 *
 *  - websocket_reconnect_triggered: fires inside the daemon every
 *    time the BaseWebsocketClient idle-timeout watchdog forces a
 *    reconnect. Throttled by canonical cache so transient blips
 *    don't spam the operator.
 *
 *  - price_data_stale: fires from the kraite:cron-check-stale-data
 *    cron when any enabled exchange_symbol's mark_price_synced_at is
 *    older than 1 minute. Indicates the WS reconnect loop is no
 *    longer healing the underlying problem (URL deprecation, IP ban,
 *    upstream outage). Operator action required.
 *
 * Idempotent via updateOrInsert on canonical.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('notifications')->updateOrInsert(
            ['canonical' => 'websocket_reconnect_triggered'],
            [
                'title' => 'WebSocket Idle-Timeout Reconnect',
                'description' => 'The mark-price WebSocket received zero frames for the idle-timeout window — daemon is forcing a reconnect',
                'detailed_description' => 'Sent by the BaseWebsocketClient inside kraite:stream-binance-prices when no frames arrive for the configured idle threshold (default 30s). One Pushover per reconnect; cache-throttled so transient blips do not flood the operator. Repeated firings without recovery indicate an unrecoverable upstream issue (URL deprecation, IP ban, gateway outage) — escalate to investigation.',
                'usage_reference' => 'kraite:stream-binance-prices',
                'default_severity' => 'high',
                'verified' => 1,
                'is_active' => true,
                'cache_duration' => 300,
                'cache_key' => json_encode(['url']),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        DB::table('notifications')->updateOrInsert(
            ['canonical' => 'price_data_stale'],
            [
                'title' => 'Mark Price Data Stale',
                'description' => 'One or more enabled exchange_symbols have mark_price_synced_at older than 1 minute — the live price stream is not healing itself',
                'detailed_description' => 'Sent by kraite:cron-check-stale-data when a tradeable symbol has not had its mark_price refreshed within the last minute. Differs from websocket_reconnect_triggered: the reconnect alert says "the WS is hiccuping", this alert says "the data has actually gone cold". If both fire together, the daemon is stuck reconnecting to a broken endpoint. Operator action: check Binance change-notice, IP block status, gateway URL deprecations.',
                'usage_reference' => 'kraite:cron-check-stale-data',
                'default_severity' => 'critical',
                'verified' => 1,
                'is_active' => true,
                'cache_duration' => 300,
                'cache_key' => json_encode(['exchange_symbol_ids']),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('notifications')
            ->whereIn('canonical', ['websocket_reconnect_triggered', 'price_data_stale'])
            ->delete();
    }
};
