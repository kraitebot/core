<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the `group_no_progress_detected` notification canonical so the
 * SendStaleStepsNotification listener can route the new
 * StaleStepsDetected(reason='group_no_progress') event to a Pushover
 * canonical without operators having to re-run db:seed manually.
 *
 * Idempotent — `updateOrInsert` matches on canonical so re-running this
 * migration (e.g., after a `migrate:fresh` followed by KraiteSeeder)
 * never produces duplicate rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('notifications')->updateOrInsert(
            ['canonical' => 'group_no_progress_detected'],
            [
                'title' => 'Dispatcher Group Stalled - No Terminal Progress',
                'description' => 'Triggered when a dispatcher group has Pending steps but no terminal-state step has been updated within the watchdog threshold (default 10 minutes)',
                'detailed_description' => 'This CRITICAL notification is sent when a dispatcher group has accumulated Pending work but is no longer concluding any step in a terminal state. '
                    .'Generalised stall detection that catches cleanup-phase wedges the per-step detector misses — '
                    .'no individual step is stuck, but no group-level work is making progress either. '
                    .'The 2026-04-25 incident hid this exact failure mode for 16h before manual intervention; this alarm closes that blind spot.',
                'usage_reference' => 'RecoverStaleStepsCommand --watchdog-progress',
                'default_severity' => 'critical',
                'verified' => 1,
                'is_active' => true,
                'cache_duration' => 600,
                'cache_key' => json_encode(['group']),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('notifications')
            ->where('canonical', 'group_no_progress_detected')
            ->delete();
    }
};
