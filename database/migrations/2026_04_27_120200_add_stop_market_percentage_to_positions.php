<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table): void {
            $table->decimal('stop_market_percentage', 5, 2)
                ->nullable()
                ->after('profit_percentage')
                ->comment('Snapshot of the resolved SL percentage at position-prepare time. Frozen for the life of the position.');
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table): void {
            $table->dropColumn('stop_market_percentage');
        });
    }
};
