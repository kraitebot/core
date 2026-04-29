<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('subscription_renews_at')
                ->nullable()
                ->after('trial_started_at')
                ->comment('Next renewal tick. Renewal cron debits one month and pushes this forward by 30 days when wallet covers the rate. NULL until first renewal is established (e.g. before trial-end or before any top-up).');

            $table->timestamp('subscription_paused_at')
                ->nullable()
                ->after('subscription_renews_at')
                ->comment('Set when the user voluntarily pauses the subscription. Renewal cron skips paused users. On resume the renewal anchor is pushed forward by the pause duration.');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['subscription_renews_at', 'subscription_paused_at']);
        });
    }
};
