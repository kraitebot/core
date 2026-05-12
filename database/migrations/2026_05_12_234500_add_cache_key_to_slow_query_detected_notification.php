<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Add cache_key to slow_query_detected notification
|--------------------------------------------------------------------------
|
| `slow_query_detected` was the only hot-path canonical falling back to the
| database-level throttle (`notification_logs::exists()` + skip). Two
| workers hitting a slow query in the same window can race past `exists()`
| before either writes an audit log row, both pass the check, and both
| emit. With `cache_duration=300`, the worst case is duplicated alerts
| every 5 minutes under concurrent load.
|
| Switching to cache-based throttling via `Cache::add()` (atomic SETNX on
| Redis) eliminates the race. Keyed by `connection` so each database
| connection (default mysql, read-replica, etc.) gets its own throttle
| window — one alert per connection per 5 min — broad enough to flag a
| systemic slow-query problem, narrow enough that a slow read-replica
| query doesn't silence a slow primary.
*/

return new class extends Migration
{
    public function up(): void
    {
        DB::table('notifications')
            ->where('canonical', 'slow_query_detected')
            ->update([
                'cache_key' => json_encode(['connection']),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('notifications')
            ->where('canonical', 'slow_query_detected')
            ->update([
                'cache_key' => null,
                'updated_at' => now(),
            ]);
    }
};
