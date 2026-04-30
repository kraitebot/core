<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seeds three ops canonicals fired by the Binance user-data-stream
 * daemon and its keepalive cron:
 *
 *  - binance_user_data_account_connected: fires when the daemon
 *    successfully opens a per-account WebSocket. Operationally useful
 *    confirmation on daemon startup; cache-throttled per account so
 *    re-inits do not flood when many accounts come online together.
 *
 *  - binance_user_data_account_init_failed: fires when initAccount()
 *    raises for a specific account (REST listenKey error, WS handshake
 *    error, decode failure on first frame). Other accounts on the same
 *    daemon stay alive — this alert is per-account, not daemon-wide.
 *
 *  - binance_user_data_listen_key_expired: fires when Binance sends a
 *    listenKeyExpired frame for an account. The daemon recovers
 *    automatically by minting a fresh key + reopening the WS, but the
 *    operator should know — frequent firings indicate API-key
 *    revocation or aggressive Binance-side cleanup.
 *
 *  - binance_listen_key_keepalive_failed: fires from the keepalive
 *    cron after the third consecutive failure to refresh a listenKey.
 *    Threshold is deliberate — a single transient REST failure does
 *    not reach the operator; only a sustained inability to refresh.
 *
 * Idempotent via updateOrInsert on canonical.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('notifications')->updateOrInsert(
            ['canonical' => 'binance_user_data_account_connected'],
            [
                'title' => 'Binance User Data Stream — Account Connected',
                'description' => 'The user-data daemon opened a WebSocket for this Binance account.',
                'detailed_description' => 'Sent by kraite:stream-binance-user-data on initAccount() success. Useful for confirming per-account stream health on daemon startup. Cache-throttled per account_id so re-inits triggered by listenKey expiry do not spam the operator.',
                'usage_reference' => 'kraite:stream-binance-user-data',
                'default_severity' => 'low',
                'verified' => 1,
                'is_active' => true,
                'cache_duration' => 300,
                'cache_key' => json_encode(['account_id']),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        DB::table('notifications')->updateOrInsert(
            ['canonical' => 'binance_user_data_account_init_failed'],
            [
                'title' => 'Binance User Data Stream — Account Init Failed',
                'description' => 'The user-data daemon could not initialise the WebSocket for this account.',
                'detailed_description' => 'Sent by kraite:stream-binance-user-data when initAccount() raises (REST listenKey error, WS handshake failure, decode failure on first frame). Per-account error isolation keeps the daemon and other accounts running; the failing account is retried on the next 60-second discovery sweep. Repeated firings imply an account-specific blocker (revoked API key, IP whitelist mismatch).',
                'usage_reference' => 'kraite:stream-binance-user-data',
                'default_severity' => 'high',
                'verified' => 1,
                'is_active' => true,
                'cache_duration' => 600,
                'cache_key' => json_encode(['account_id']),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        DB::table('notifications')->updateOrInsert(
            ['canonical' => 'binance_user_data_listen_key_expired'],
            [
                'title' => 'Binance User Data Stream — listenKey Expired',
                'description' => 'Binance pushed a listenKeyExpired frame; the daemon is re-initialising this account.',
                'detailed_description' => 'Sent by kraite:stream-binance-user-data when an authenticated stream emits the listenKeyExpired event. The daemon recovers automatically (drops the WS, mints a fresh listenKey via REST, reopens). Frequent firings on the same account suggest aggressive Binance-side key invalidation — investigate the account API-key configuration.',
                'usage_reference' => 'kraite:stream-binance-user-data',
                'default_severity' => 'medium',
                'verified' => 1,
                'is_active' => true,
                'cache_duration' => 600,
                'cache_key' => json_encode(['account_id']),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        DB::table('notifications')->updateOrInsert(
            ['canonical' => 'binance_listen_key_keepalive_failed'],
            [
                'title' => 'Binance listenKey Keepalive Failed',
                'description' => 'Three consecutive failures refreshing a listenKey via the keepalive cron.',
                'detailed_description' => 'Sent by kraite:cron-refresh-binance-listen-keys after the third consecutive PUT-listenKey failure for the same account. The threshold is deliberate — single transient REST blips do not reach the operator. A sustained failure means the listenKey will expire (60min Binance auto-expiry) and the user-data WebSocket will die unless the operator intervenes.',
                'usage_reference' => 'kraite:cron-refresh-binance-listen-keys',
                'default_severity' => 'high',
                'verified' => 1,
                'is_active' => true,
                'cache_duration' => 1800,
                'cache_key' => json_encode(['account_id']),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('notifications')
            ->whereIn('canonical', [
                'binance_user_data_account_connected',
                'binance_user_data_account_init_failed',
                'binance_user_data_listen_key_expired',
                'binance_listen_key_keepalive_failed',
            ])
            ->delete();
    }
};
