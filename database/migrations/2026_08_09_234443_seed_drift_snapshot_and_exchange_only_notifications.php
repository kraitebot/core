<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $notifications = [
            'position_exchange_only_detected' => [
                'title' => 'Untracked Exchange Position Detected',
                'description' => 'The exchange reports an open position that has no matching open Kraite position',
                'detailed_description' => 'Sent by kraite:cron-check-drifts after an exchange-only position survives fresh local-state revalidation. Alert-only: no position or order is changed automatically.',
                'default_severity' => 'critical',
                'cache_key' => ['account', 'symbol', 'direction'],
            ],
            'orders_exchange_only_detected' => [
                'title' => 'Untracked Exchange Orders Detected',
                'description' => 'The exchange reports open orders that have no matching Kraite order rows',
                'detailed_description' => 'Sent by kraite:cron-check-drifts after exchange-only orders survive fresh account-scoped local-order revalidation. Alert-only: ownership must be verified before cancellation.',
                'default_severity' => 'critical',
                'cache_key' => ['account'],
            ],
            'account_drift_snapshot_failed' => [
                'title' => 'Drift Snapshot Incomplete',
                'description' => 'A required exchange endpoint failed, so Kraite made no drift conclusion',
                'detailed_description' => 'Sent by kraite:cron-check-drifts when positions, regular orders, the venue-required conditional-order endpoint, or final missing-order confirmation cannot provide a trustworthy snapshot. No drift repair or alert is produced from partial data.',
                'default_severity' => 'high',
                'cache_key' => ['account'],
            ],
        ];

        foreach ($notifications as $canonical => $notification) {
            DB::table('notifications')->updateOrInsert(
                ['canonical' => $canonical],
                [
                    'title' => $notification['title'],
                    'description' => $notification['description'],
                    'detailed_description' => $notification['detailed_description'],
                    'usage_reference' => 'kraite:cron-check-drifts',
                    'default_severity' => $notification['default_severity'],
                    'verified' => 1,
                    'is_active' => true,
                    'cache_duration' => 60,
                    'cache_key' => json_encode($notification['cache_key'], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }

    public function down(): void
    {
        DB::table('notifications')
            ->whereIn('canonical', [
                'position_exchange_only_detected',
                'orders_exchange_only_detected',
                'account_drift_snapshot_failed',
            ])
            ->delete();
    }
};
