<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `users.telegram_chat_id` so the AlertNotification's
 * `toTelegram()` channel has a per-user routing target.
 *
 * Each user populates this once after DM-ing the bot
 * (BotFather-created bot configured via `TELEGRAM_BOT_TOKEN`).
 * Empty / null = telegram channel disabled for that user; the
 * channel falls through silently (`routeNotificationForTelegram`
 * returns null → Laravel skips delivery for that channel).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('telegram_chat_id')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('telegram_chat_id');
        });
    }
};
