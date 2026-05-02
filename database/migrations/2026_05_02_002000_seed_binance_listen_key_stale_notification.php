<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Seed `binance_listen_key_stale` notification
|--------------------------------------------------------------------------
|
| Fired by the `kraite:cron-check-binance-listen-keys-stale` watchdog
| when an active Binance account either has NO row in
| binance_listen_keys (daemon never initialised it) OR the row's
| last_keep_alive_at is older than 30 minutes (keepalive cron not
| firing). Per-account cache key (1800s = 30min) so a sustained
| failure alerts on every check window without spamming.
*/

return new class extends Migration
{
    public function up(): void
    {
        DB::table('notifications')->updateOrInsert(
            ['canonical' => 'binance_listen_key_stale'],
            [
                'title' => 'Binance Listen Key — Stale or Missing',
                'description' => 'An active Binance account either has no listenKey row or the last keepalive timestamp is older than the threshold.',
                'detailed_description' => 'Raised by kraite:cron-check-binance-listen-keys-stale when an account passes the eligibility filter (api_system=binance, is_active=true, binance_api_key NOT NULL) but its binance_listen_keys row is either missing past the 2-minute grace OR its last_keep_alive_at is older than 30 minutes. Threshold is well below Binance\'s 60-minute hard expiry so the operator has time to respond before the WS dies. Does NOT auto-restart anything — surface only.',
                'usage_reference' => 'kraite:cron-check-binance-listen-keys-stale',
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
        DB::table('notifications')->where('canonical', 'binance_listen_key_stale')->delete();
    }
};
