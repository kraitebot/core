<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->renameColumn('daily_rate_usdt', 'monthly_rate_usdt');
        });

        DB::statement('UPDATE subscriptions SET monthly_rate_usdt = monthly_rate_usdt * 30');
    }

    public function down(): void
    {
        DB::statement('UPDATE subscriptions SET monthly_rate_usdt = monthly_rate_usdt / 30');

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->renameColumn('monthly_rate_usdt', 'daily_rate_usdt');
        });
    }
};
