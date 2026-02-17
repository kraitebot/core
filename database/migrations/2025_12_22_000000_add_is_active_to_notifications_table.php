<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Kraite\Core\Database\Seeders\DisableStaleStepsNotificationSeeder;

/**
 * Add is_active column to notifications table.
 *
 * Allows disabling specific notifications without code changes.
 * Default is true (active).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true)->after('cache_key');
        });

        // Disable the stale_priority_steps_detected notification
        (new DisableStaleStepsNotificationSeeder)->run();
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropColumn('is_active');
        });
    }
};
