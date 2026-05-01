<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Seed `binance_user_data_account_reaped` notification
|--------------------------------------------------------------------------
|
| Fired by the user-data daemon when it tears down an account's WS slot
| because the account is no longer eligible (is_active flipped to false,
| api key removed, account deleted). Per-account cache key so a single
| deactivation alerts once and an oscillating activation flip alerts on
| every transition.
*/

return new class extends Migration
{
    public function up(): void
    {
        DB::table('notifications')->updateOrInsert(
            ['canonical' => 'binance_user_data_account_reaped'],
            [
                'title' => 'Binance User Data Stream — Account Reaped',
                'description' => 'The user-data daemon closed the WebSocket for this account because it became ineligible (is_active=false, api key removed, or deleted).',
                'detailed_description' => 'Raised by kraite:stream-binance-user-data when the discovery sweep finds a slot whose account is no longer eligible. Eligibility = api_system=binance AND is_active=true AND binance_api_key IS NOT NULL. The daemon closes the Pawl connection cleanly, removes the slot, and deletes the binance_listen_keys row so the keepalive cron stops refreshing a key for an account that should not be streaming. A subsequent re-activation triggers the spawn path on the next 60s sweep.',
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
    }

    public function down(): void
    {
        DB::table('notifications')->where('canonical', 'binance_user_data_account_reaped')->delete();
    }
};
