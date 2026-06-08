<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notification Threshold — an opt-in escalation gate that sits on top of the
 * existing Throttler.
 *
 * The Throttler (cache_duration / cache_key) decides whether an occurrence is
 * allowed into notification_logs at all. The Threshold then watches the rate of
 * those logged occurrences: a notification is only physically sent once it has
 * recurred `threshold_max_notifications` times within a rolling
 * `threshold_max_duration_minutes` window. Sub-threshold occurrences are still
 * recorded (audit) but not delivered — `notification_logs.passed_threshold`
 * carries that distinction (true = delivered, false = recorded-only).
 *
 * Inert by default: has_threshold defaults false, so every existing
 * notification behaves exactly as before. Thresholds are switched on per
 * notification, by hand. passed_threshold defaults true so every row written by
 * the existing send path (NotificationLogListener) is correctly marked as
 * delivered without touching that code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->boolean('has_threshold')
                ->default(false)
                ->after('cache_key')
                ->comment('Opt-in: gate delivery behind a recurrence threshold (see threshold_max_* columns)');

            $table->unsignedInteger('threshold_max_notifications')
                ->nullable()
                ->after('has_threshold')
                ->comment('How many logged occurrences within the window are required before the notification is physically sent');

            $table->unsignedInteger('threshold_max_duration_minutes')
                ->nullable()
                ->after('threshold_max_notifications')
                ->comment('Rolling window in minutes over which threshold_max_notifications occurrences must land to breach');
        });

        Schema::table('notification_logs', function (Blueprint $table): void {
            $table->boolean('passed_threshold')
                ->default(true)
                ->after('status')
                ->comment('Whether this occurrence was physically sent (true) or recorded-only because it had not yet breached its notification threshold (false). Defaults true so the existing send path needs no change.');

            // Serves the threshold pending count: held rows for a notification
            // since the last breach, inside the window
            // (notification_id + passed_threshold=false + id > anchor + created_at > window).
            $table->index(
                ['notification_id', 'passed_threshold', 'created_at'],
                'notification_logs_threshold_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('notification_logs', function (Blueprint $table): void {
            $table->dropIndex('notification_logs_threshold_index');
            $table->dropColumn('passed_threshold');
        });

        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropColumn([
                'has_threshold',
                'threshold_max_notifications',
                'threshold_max_duration_minutes',
            ]);
        });
    }
};
