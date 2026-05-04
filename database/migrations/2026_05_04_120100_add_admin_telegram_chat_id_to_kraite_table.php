<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `kraite.admin_telegram_chat_id` so the virtual admin user
 * built by `Kraite::admin()` carries a routing target for the
 * Telegram channel. Mirrors the existing
 * `admin_pushover_user_key` shape.
 *
 * Pair with `users.telegram_chat_id` (added in the migration that
 * runs immediately before this one). Empty / null = telegram
 * channel disabled for the admin route — the channel falls
 * through silently in the dispatcher.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kraite', function (Blueprint $table): void {
            $table->string('admin_telegram_chat_id')->nullable()->after('admin_pushover_application_key');
        });
    }

    public function down(): void
    {
        Schema::table('kraite', function (Blueprint $table): void {
            $table->dropColumn('admin_telegram_chat_id');
        });
    }
};
