<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Seed `system_health_alert` generic notification
|--------------------------------------------------------------------------
|
| One notification row, used by every system-health check. Each check
| passes its own per-signal `signal` value as the cache key (e.g.
| `indicator_stale_BTCUSDT`, `redis_down`, `failed_jobs_overflow`) so
| different alerts dedupe independently while sharing the same Pushover
| / email plumbing.
|
| 5-minute throttle per signal — enough headroom that real alerts get
| through, while preventing a single bad check from flooding the
| operator. Title / body / severity are passed in via referenceData so
| the same notification row covers every health concern.
*/

return new class extends Migration
{
    public function up(): void
    {
        DB::table('notifications')->updateOrInsert(
            ['canonical' => 'system_health_alert'],
            [
                'title' => 'System Health Alert',
                'description' => 'Generic alert raised by kraite:cron-check-system-health when any of the bot\'s critical data paths goes stale or unreachable.',
                'detailed_description' => 'Single notification canonical shared by every system-health check (indicator freshness, account-balance freshness, daemon heartbeat, dispatcher tick rate, scheduler liveness, failed-jobs overflow, DB connection, Redis connection, Horizon queue depth). The cache key is the per-signal identifier so each unique failure dedupes independently — a sustained problem alerts every 5 minutes for the same signal, and a different signal gets its own 5-minute window. Title / body / severity come from the firing check\'s referenceData, so the same notification row covers all 9 concerns without seed-migration churn each time a new check is added.',
                'usage_reference' => 'kraite:cron-check-system-health',
                'default_severity' => 'high',
                'verified' => 1,
                'is_active' => true,
                'cache_duration' => 300,
                'cache_key' => json_encode(['signal']),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('notifications')->where('canonical', 'system_health_alert')->delete();
    }
};
