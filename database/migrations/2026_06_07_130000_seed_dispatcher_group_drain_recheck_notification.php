<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Kraite\Core\Database\Seeders\DispatcherGroupDrainRecheckNotificationSeeder;
use Kraite\Core\Models\Notification;

/**
 * Seed the `dispatcher_group_drain_recheck` notification row (the
 * non-critical 15-minute stall follow-up).
 */
return new class extends Migration
{
    public function up(): void
    {
        (new DispatcherGroupDrainRecheckNotificationSeeder)->run();
    }

    public function down(): void
    {
        Notification::where('canonical', 'dispatcher_group_drain_recheck')->delete();
    }
};
