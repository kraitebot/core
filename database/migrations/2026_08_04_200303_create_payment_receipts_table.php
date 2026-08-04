<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payment_receipts', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('payment_id')
                ->constrained('payments')
                ->restrictOnDelete()
                ->comment('Kraite invoice that owns this gateway payment or repeated deposit.');

            $table->string('gateway_payment_id')
                ->unique()
                ->comment('NOWPayments payment_id. One receipt makes every callback idempotent.');

            $table->string('parent_gateway_payment_id')
                ->nullable()
                ->index()
                ->comment('Original NOWPayments payment_id for repeated deposits.');

            $table->decimal('credited_amount', 14, 4)
                ->default(0)
                ->comment('Cumulative USDT credited for this individual gateway payment.');

            $table->timestamps();

            $table->index(['payment_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_receipts');
    }
};
