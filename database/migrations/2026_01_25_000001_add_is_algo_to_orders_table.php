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
     * Adds `is_algo` column to flag orders that must use Binance's Algo Order API
     * for placement and querying (STOP_MARKET, TAKE_PROFIT_MARKET, etc.).
     *
     * @see https://developers.binance.com/docs/derivatives/usds-margined-futures/trade/rest-api/New-Algo-Order
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->boolean('is_algo')
                ->default(false)
                ->after('exchange_order_id')
                ->comment('True for orders using Binance Algo Order API (STOP_MARKET, etc.)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('is_algo');
        });
    }
};
