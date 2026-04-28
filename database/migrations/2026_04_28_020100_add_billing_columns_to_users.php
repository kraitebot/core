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
            $table->decimal('wallet_balance_usdt', 14, 4)
                ->default(0)
                ->after('subscription_id')
                ->comment('Live USDT balance. Credited on top-up + bonus, debited daily by the subscription cron.');

            $table->timestamp('trial_started_at')
                ->nullable()
                ->after('wallet_balance_usdt')
                ->comment('Stamped on first start-trading action. NULL while trial is unused; trial expires at start + subscription.trial_days.');

            $table->foreignId('active_account_id')
                ->nullable()
                ->after('trial_started_at')
                ->constrained('accounts')
                ->nullOnDelete()
                ->comment('Designated single trading account when user is on a tier capped at 1 account (Starter). NULL on Unlimited.');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['active_account_id']);
            $table->dropColumn(['wallet_balance_usdt', 'trial_started_at', 'active_account_id']);
        });
    }
};
