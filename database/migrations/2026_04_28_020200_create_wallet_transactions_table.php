<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->restrictOnDelete();

            $table->string('type', 32)
                ->comment('debit_subscription | credit_topup | credit_topup_bonus | credit_admin | debit_admin');

            $table->decimal('amount_usdt', 14, 4)
                ->comment('Signed: positive = credit, negative = debit.');

            $table->decimal('balance_after', 14, 4)
                ->comment('Wallet balance snapshot immediately after this row was written. For dispute forensics.');

            $table->string('description');

            $table->json('meta')
                ->nullable()
                ->comment('Free-form context: cron_run_id, payment_id, admin_user_id, bonus_pct, etc.');

            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};
