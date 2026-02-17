<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds exchange-specific columns for minimum order size calculations.
 *
 * Kraken: Uses `contractSize` from instruments API (value per contract, typically $1).
 * KuCoin: Uses `lotSize` (minimum contract increment) and `multiplier` (contract value multiplier).
 *
 * These allow calculating min_notional at trade time without extra API calls:
 * - Kraken: min_value = kraken_min_order_size * current_price (where contractSize=1 means $1/contract)
 * - KuCoin: min_value = kucoin_lot_size * kucoin_multiplier * current_price
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exchange_symbols', function (Blueprint $table) {
            // Kraken: minimum order size in contracts
            $table->decimal('kraken_min_order_size', 20, 8)->nullable()->after('min_notional');

            // KuCoin: lot size (minimum contract increment) and multiplier (contract value)
            $table->decimal('kucoin_lot_size', 20, 8)->nullable()->after('kraken_min_order_size');
            $table->decimal('kucoin_multiplier', 20, 10)->nullable()->after('kucoin_lot_size');
        });
    }

    public function down(): void
    {
        Schema::table('exchange_symbols', function (Blueprint $table) {
            $table->dropColumn([
                'kraken_min_order_size',
                'kucoin_lot_size',
                'kucoin_multiplier',
            ]);
        });
    }
};
