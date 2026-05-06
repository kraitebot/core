<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the `position_structure_broken` notification canonical consumed
 * by the third audit scope inside kraite:cron-check-drifts.
 *
 * Fires when an `active` position is found with one or more of its
 * expected order rows (1 MARKET + N LIMIT + 1 PROFIT-* + 1 STOP-MARKET)
 * either absent or in a structurally-dead status (CANCELLED / EXPIRED /
 * REJECTED). Throttled per position via the cache key so each broken
 * incident generates a single notification.
 *
 * Idempotent via updateOrInsert on canonical.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('notifications')->updateOrInsert(
            ['canonical' => 'position_structure_broken'],
            [
                'title' => 'Position Structure Broken — Opens Halted',
                'description' => 'An active position is missing one or more of its expected orders — new opens have been halted globally',
                'detailed_description' => 'Sent by kraite:cron-check-drifts (Scope 3) when a position in `active` status is missing the market entry, take profit, stop loss, or any of its planned limit orders (rows physically absent OR sitting in CANCELLED / EXPIRED / REJECTED). The audit also flips `kraite.allow_opening_positions = false` automatically so the existing HasTradingGuards gate halts every new-position dispatch path. The flag is sticky on purpose — recovery is operator-driven: inspect the position, fix the missing component, then flip the flag back to true. Throttled per position id so each broken incident produces a single notification.',
                'usage_reference' => 'kraite:cron-check-drifts',
                'default_severity' => 'critical',
                'verified' => 1,
                'is_active' => true,
                'cache_duration' => 86400,
                'cache_key' => json_encode(['position']),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('notifications')
            ->where('canonical', 'position_structure_broken')
            ->delete();
    }
};
