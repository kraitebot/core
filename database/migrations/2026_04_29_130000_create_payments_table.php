<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained('users')
                ->restrictOnDelete()
                ->comment('User who initiated the top-up. Restricted on delete so we never lose audit trail.');

            $table->string('nowpayments_payment_id')
                ->nullable()
                ->unique()
                ->comment('NOWPayments-assigned payment ID. Null until the gateway confirms the invoice was created. Unique to dedupe webhook retries.');

            $table->string('order_id')
                ->index()
                ->comment('Our internal reference passed as order_id to the gateway. Format: kraite-payment-{id}.');

            $table->string('pay_currency', 32)
                ->nullable()
                ->comment('Coin the user actually paid in (e.g. btc, eth, ltc).');

            $table->decimal('pay_amount', 24, 12)
                ->nullable()
                ->comment('Amount of pay_currency the user paid.');

            $table->decimal('price_amount', 14, 4)
                ->comment('USDT amount the user requested to top up. The starting price the invoice was created for.');

            $table->decimal('outcome_amount', 14, 4)
                ->nullable()
                ->comment('USDT amount actually credited to the wallet after gateway conversion.');

            $table->string('outcome_currency', 16)
                ->default('usdt');

            $table->string('status', 32)
                ->default('pending')
                ->comment('Latest status from the gateway: pending, waiting, confirming, confirmed, sending, partially_paid, finished, failed, refunded, expired.');

            $table->string('invoice_url', 500)
                ->nullable()
                ->comment('NOWPayments hosted invoice URL the user is redirected to.');

            $table->timestamp('credited_at')
                ->nullable()
                ->comment('Set the first time we credit the wallet against this payment. Null while pending. Used for idempotency on webhook retries.');

            $table->json('raw_payload')
                ->nullable()
                ->comment('Last webhook payload received for this payment. Replaced on each update — full audit lives in app logs.');

            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
