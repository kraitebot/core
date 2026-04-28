<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the two notification canonicals consumed by the proactive drift
 * spotter (kraite:cron-check-drifts):
 *
 *  - position_drift_detected: fires per drifted active position once the
 *    spotter has dispatched PrepareSyncOrdersJob.
 *  - position_orphan_orders_detected: fires per parent position whose
 *    closed/cancelled/failed state still carries open orders on the
 *    exchange after the 10-minute quiet window.
 *
 * Idempotent via updateOrInsert on canonical.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('notifications')->updateOrInsert(
            ['canonical' => 'position_drift_detected'],
            [
                'title' => 'Position Drift Detected',
                'description' => 'Active position disagreed with the exchange after the 10-minute quiet window — proactive spotter dispatched a sync-orders heal',
                'detailed_description' => 'Sent by kraite:cron-check-drifts when an `active` position has been quiet for more than 10 minutes (no order updated_at within that window) and the drift comparator finds disagreement on position-level fields or any of its orders. The spotter dispatches PrepareSyncOrdersJob immediately; this notification is the audit trail. One pushover per drifted position per cron cycle — re-fires every 5 minutes while drift persists.',
                'usage_reference' => 'kraite:cron-check-drifts',
                'default_severity' => 'high',
                'verified' => 1,
                'is_active' => true,
                'cache_duration' => 60,
                'cache_key' => json_encode(['position']),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        DB::table('notifications')->updateOrInsert(
            ['canonical' => 'position_orphan_orders_detected'],
            [
                'title' => 'Orphan Open Orders Detected',
                'description' => 'A non-active position still carries open orders on the exchange — spotter dispatched per-order cancellations',
                'detailed_description' => 'Sent by kraite:cron-check-drifts when one or more orders in NEW or PARTIALLY_FILLED status are attached to a position whose own status is closed/cancelled/failed AND none of those orders has been touched within the last 10 minutes. The spotter dispatches CancelSingleAlgoOrderJob for each. One pushover per parent position summarising every orphan order found there.',
                'usage_reference' => 'kraite:cron-check-drifts',
                'default_severity' => 'high',
                'verified' => 1,
                'is_active' => true,
                'cache_duration' => 60,
                'cache_key' => json_encode(['position']),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('notifications')
            ->whereIn('canonical', ['position_drift_detected', 'position_orphan_orders_detected'])
            ->delete();
    }
};
