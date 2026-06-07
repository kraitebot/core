<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user notification toggle, the middle tier of the notification
 * cascade: global (kraite.notifications_enabled) → this user flag →
 * except Critical-severity notifications, which are always delivered.
 * NULL is treated as enabled, so existing users keep receiving
 * notifications until they explicitly opt out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('notifications_enabled')
                ->nullable()
                ->after('notification_channels')
                ->comment('Per-user notification toggle. NULL or true = receive notifications (subject to the global kraite.notifications_enabled master switch). false = suppress this user\'s notifications EXCEPT Critical-severity ones, which are always delivered.');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('notifications_enabled');
        });
    }
};
