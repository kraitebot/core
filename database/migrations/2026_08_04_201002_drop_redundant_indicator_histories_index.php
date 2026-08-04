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
        Schema::table('indicator_histories', function (Blueprint $table): void {
            $table->dropIndex('idx_indhist_es_i_tf_ts');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('indicator_histories', function (Blueprint $table): void {
            $table->index(
                ['exchange_symbol_id', 'indicator_id', 'timeframe', 'timestamp'],
                'idx_indhist_es_i_tf_ts',
            );
        });
    }
};
