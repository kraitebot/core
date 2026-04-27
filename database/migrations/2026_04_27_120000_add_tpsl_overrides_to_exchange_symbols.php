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
            $table->decimal('profit_percentage', 6, 3)
                ->nullable()
                ->after('was_backtesting_approved')
                ->comment('Per-symbol TP override resolved from backtesting. NULL = fallback to account.profit_percentage.');

            $table->decimal('stop_market_percentage', 5, 2)
                ->nullable()
                ->after('profit_percentage')
                ->comment('Per-symbol SL override resolved from backtesting. NULL = fallback to account.stop_market_initial_percentage.');
        });
    }

    public function down(): void
    {
        Schema::table('exchange_symbols', function (Blueprint $table): void {
            $table->dropColumn(['profit_percentage', 'stop_market_percentage']);
        });
    }
};
