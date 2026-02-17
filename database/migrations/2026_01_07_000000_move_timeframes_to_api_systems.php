<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Move indicator timeframes from TradeConfiguration to ApiSystem.
 *
 * This allows each exchange to define its own supported timeframes,
 * since not all exchanges support all timeframes (e.g., Kraken doesn't support 6h).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Add timeframes column to api_systems
        Schema::table('api_systems', function (Blueprint $table): void {
            $table->json('timeframes')
                ->nullable()
                ->after('websocket_class')
                ->comment('Supported kline timeframes for this exchange (5m to 1d range)');
        });

        // Drop indicator_timeframes from trade_configuration
        Schema::table('trade_configuration', function (Blueprint $table): void {
            $table->dropColumn('indicator_timeframes');
        });

        // Populate timeframes for each exchange
        $this->seedExchangeTimeframes();
    }

    public function down(): void
    {
        // Add indicator_timeframes back to trade_configuration
        Schema::table('trade_configuration', function (Blueprint $table): void {
            $table->json('indicator_timeframes')
                ->nullable()
                ->comment('Taapi timeframes considered for the trade configuration');
        });

        // Drop timeframes from api_systems
        Schema::table('api_systems', function (Blueprint $table): void {
            $table->dropColumn('timeframes');
        });
    }

    /**
     * Seed exchange timeframes directly in migration.
     * Kraken doesn't support 6h timeframe.
     */
    private function seedExchangeTimeframes(): void
    {
        $exchangeTimeframes = [
            'binance' => ['5m', '1h', '4h', '12h', '1d'],
            'bybit' => ['5m', '1h', '4h', '12h', '1d'],
            'kraken' => ['5m', '1h', '4h', '12h', '1d'],
            'kucoin' => ['5m', '1h', '4h', '12h', '1d'],
            'bitget' => ['5m', '1h', '4h', '12h', '1d'],
        ];

        foreach ($exchangeTimeframes as $canonical => $timeframes) {
            \Illuminate\Support\Facades\DB::table('api_systems')
                ->where('canonical', $canonical)
                ->update(['timeframes' => json_encode($timeframes)]);
        }
    }
};
