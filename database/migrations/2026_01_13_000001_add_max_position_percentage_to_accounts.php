<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds max_position_percentage column to accounts table.
     * This defines the maximum percentage of account balance that can be used
     * for a single position's total margin (market + all limit orders).
     */
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->decimal('max_position_percentage', 5, 2)->default(5.00)
                ->comment('Max % of account balance for a single position total margin')
                ->after('margin_mode');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('max_position_percentage');
        });
    }
};
