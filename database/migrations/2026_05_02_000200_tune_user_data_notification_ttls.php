<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Tune user-data notification TTLs + add new daemon-resilience canonicals
|--------------------------------------------------------------------------
|
| Two changes:
|
| 1. Bump `binance_user_data_account_init_failed` from 10min → 1h.
|    The daemon's discovery sweep retries every 60s. With a 10min
|    cache window, a permanently-broken API key fires up to 6 alerts
|    per hour. 1h is the right operator-attention cadence for a
|    persistent failure — escalation by repetition, not flooding.
|
| 2. Seed two new canonicals:
|    - `websocket_reconnect_storm` raised by BaseWebsocketClient when
|      a connection has flapped past the storm threshold without a
|      successful frame. Caches per-URL so two streams degrading
|      simultaneously each get their own alert.
|    - `binance_user_data_memory_restart` raised by the daemon's
|      memory watchdog before it stops the loop. Caches per-PID so
|      a respawn followed by another memory event still alerts.
*/

return new class extends Migration
{
    public function up(): void
    {
        DB::table('notifications')
            ->where('canonical', 'binance_user_data_account_init_failed')
            ->update(['cache_duration' => 3600, 'updated_at' => now()]);

        DB::table('notifications')->updateOrInsert(
            ['canonical' => 'websocket_reconnect_storm'],
            [
                'title' => 'WebSocket — Reconnect Storm',
                'description' => 'A WebSocket connection has been flapping (connect → close → reconnect) past the storm threshold without receiving a frame.',
                'detailed_description' => 'Raised by Kraite\\Core\\Abstracts\\BaseWebsocketClient::reconnect() when reconnectAttempt crosses reconnectStormThreshold (default 10) before any successful frame. The reconnect path itself is unbounded by design (supervised daemons should always be retrying); this alert exists so a slow-degrade scenario — a single stream flapping every 30s for hours — does not slip past ops unnoticed. Resets on the next successful frame.',
                'usage_reference' => 'Kraite\\Core\\Abstracts\\BaseWebsocketClient',
                'default_severity' => 'high',
                'verified' => 1,
                'is_active' => true,
                'cache_duration' => 1800,
                'cache_key' => json_encode(['url']),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        DB::table('notifications')->updateOrInsert(
            ['canonical' => 'binance_user_data_memory_restart'],
            [
                'title' => 'Binance User Data Stream — Memory Limit Hit',
                'description' => 'The user-data daemon crossed its memory ceiling and is exiting for supervisor restart.',
                'detailed_description' => 'Raised by kraite:stream-binance-user-data when memory_get_usage(true) crosses MEMORY_LIMIT_BYTES. The daemon then calls Loop::stop(); supervisor.autorestart=true respawns a fresh process within seconds. binance_listen_keys rows survive the restart so the new daemon picks up where the old left off. A handful of frames may be missed during the restart window — the keepalive cron preserves the keys and the workers reconnect on the next loop tick.',
                'usage_reference' => 'kraite:stream-binance-user-data',
                'default_severity' => 'medium',
                'verified' => 1,
                'is_active' => true,
                'cache_duration' => 600,
                'cache_key' => json_encode(['pid']),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('notifications')
            ->where('canonical', 'binance_user_data_account_init_failed')
            ->update(['cache_duration' => 600, 'updated_at' => now()]);

        DB::table('notifications')
            ->whereIn('canonical', ['websocket_reconnect_storm', 'binance_user_data_memory_restart'])
            ->delete();
    }
};
