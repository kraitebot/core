<?php

declare(strict_types=1);

namespace Kraite\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Kraite\Core\Models\Notification;

/**
 * Registers the non-critical follow-up notification fired 15 minutes after
 * a `group_no_progress_detected` stall, reporting whether the wedged
 * dispatcher group drained on its own (Info) or is still stalled (High).
 * cache_duration 0 = never throttled: each scheduled recheck always
 * delivers its verdict.
 */
final class DispatcherGroupDrainRecheckNotificationSeeder extends Seeder
{
    public function run(): void
    {
        Notification::updateOrCreate(
            ['canonical' => 'dispatcher_group_drain_recheck'],
            [
                'canonical' => 'dispatcher_group_drain_recheck',
                'title' => 'Dispatcher Group Drain Recheck',
                'description' => 'Non-critical 15-minute follow-up to a group_no_progress_detected stall. Reports whether the wedged dispatcher group drained on its own (Info) or is still stalled and needs manual intervention (High).',
                'default_severity' => 'info',
                'cache_duration' => 0,
                'cache_key' => json_encode(['canonical' => 'dispatcher_group_drain_recheck']),
                'is_active' => true,
                'verified' => true,
            ],
        );
    }
}
