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
        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('credited_amount', 14, 4)
                ->default(0)
                ->after('outcome_amount')
                ->comment('Cumulative USDT already credited for this payment. Supports incremental partially_paid webhooks without duplicate credits.');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('credited_amount');
        });
    }
};
