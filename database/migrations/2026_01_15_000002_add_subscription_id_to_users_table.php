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
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('subscription_id')
                ->nullable()
                ->after('id')
                ->constrained('subscriptions');
        });

        // Assign subscriptions to existing users:
        // User 1 (Bruno - Binance+Bybit admin) -> Unlimited (id=2)
        // All other users -> Starter (id=1)
        DB::table('users')->where('id', 1)->update(['subscription_id' => 2]);
        DB::table('users')->where('id', '!=', 1)->whereNull('subscription_id')->update(['subscription_id' => 1]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['subscription_id']);
            $table->dropColumn('subscription_id');
        });
    }
};
