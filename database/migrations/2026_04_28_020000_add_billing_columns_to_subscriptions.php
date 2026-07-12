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
            $table->decimal('daily_rate_usdt', 12, 4)
                ->default(0)
                ->after('description')
                ->comment('Daily fee debited from the user wallet for this tier.');

            $table->unsignedSmallInteger('trial_days')
                ->default(7)
                ->after('daily_rate_usdt')
                ->comment('Free-trial duration granted on first activation, per Kraite user.');
        });

        // The entry tier was canonical `starter` when this migration first
        // shipped; the seeder now creates it as `basic` directly, so fresh
        // installs must match either name.
        DB::table('subscriptions')->whereIn('canonical', ['starter', 'basic'])->update([
            'daily_rate_usdt' => 2.5,
            'trial_days' => 7,
        ]);

        DB::table('subscriptions')->where('canonical', 'unlimited')->update([
            'daily_rate_usdt' => 5.0,
            'trial_days' => 7,
        ]);
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['daily_rate_usdt', 'trial_days']);
        });
    }
};
