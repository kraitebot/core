<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * is_price_aligned — does this exchange symbol's price approximately match its
 * Binance same-asset (symbol_id) sibling?
 *
 * Two exchanges can list the SAME asset under different contract units (Binance
 * `1000FLOKI` = 1000 × KuCoin `FLOKI`), so their quoted prices legitimately
 * differ by the contract ratio. The price stream replicates Binance's mark_price
 * onto same-asset rows; for a unit-divergent contract that write is wrong (1000×
 * off). This flag records the one-shot price-alignment check run during the
 * symbol refresh: aligned (ratio ≈ 1) stays tradeable; divergent is excluded
 * from trading (scopeTradeable requires this = true) and switched off.
 *
 * Defaults true so existing rows and Binance reference rows stay tradeable until
 * a check actually proves divergence — avoids emptying the tradeable universe on
 * migrate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exchange_symbols', function (Blueprint $table): void {
            $table->boolean('is_price_aligned')->default(true)->after('overlaps_with_binance');
        });
    }

    public function down(): void
    {
        Schema::table('exchange_symbols', function (Blueprint $table): void {
            $table->dropColumn('is_price_aligned');
        });
    }
};
