<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('payments')
            ->whereNotNull('credited_at')
            ->update([
                'credited_amount' => DB::raw('COALESCE(outcome_amount, price_amount)'),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('payments')
            ->whereNotNull('credited_at')
            ->update(['credited_amount' => 0]);
    }
};
