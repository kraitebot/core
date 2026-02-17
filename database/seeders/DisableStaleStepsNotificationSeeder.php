<?php

declare(strict_types=1);

namespace Kraite\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Kraite\Core\Models\Notification;

/**
 * Disable the stale_priority_steps_detected notification.
 *
 * This notification fires when steps are stuck after promotion to priority queue,
 * which can be noisy. Disabling it via is_active flag.
 */
final class DisableStaleStepsNotificationSeeder extends Seeder
{
    public function run(): void
    {
        Notification::where('canonical', 'stale_priority_steps_detected')
            ->update(['is_active' => false]);
    }
}
