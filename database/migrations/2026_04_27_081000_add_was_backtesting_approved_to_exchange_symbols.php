<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exchange_symbols', function (Blueprint $table): void {
            $table->boolean('was_backtesting_approved')
                ->default(false)
                ->after('is_manually_enabled')
                ->comment('Set to true once the symbol has passed backtesting approval');
        });
    }

    public function down(): void
    {
        Schema::table('exchange_symbols', function (Blueprint $table): void {
            $table->dropColumn('was_backtesting_approved');
        });
    }
};
