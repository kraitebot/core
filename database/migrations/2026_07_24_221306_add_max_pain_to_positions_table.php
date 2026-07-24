<?php

declare(strict_types=1);

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
        Schema::table('positions', function (Blueprint $table): void {
            $table->decimal('max_pain', 20, 8)
                ->nullable()
                ->after('stop_market_percentage')
                ->comment('Gross quote-asset loss at the opening stop, snapshotted when the position is activated.');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table): void {
            $table->dropColumn('max_pain');
        });
    }
};
