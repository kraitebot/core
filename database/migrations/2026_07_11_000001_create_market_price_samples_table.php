<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rolling per-minute mark-price samples for the BSCS reference basket
 * (BTC + ETH/SOL/BNB/XRP). Fed by DetectMarketShockJob each detector
 * tick from `exchange_symbols.mark_price` (1s-fresh via the price
 * daemon) and pruned to a ~3h window on the same tick. Powers the
 * live-window cascade detection path — replaces the 15-minute kline
 * staleness with ~1-minute reaction (validated in
 * ~/blackswan/reports/fast-breaker-replay-20260711.txt).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_price_samples', function (Blueprint $table) {
            $table->id();
            $table->string('token', 20);
            $table->decimal('price', 20, 8);
            $table->dateTime('sampled_at');
            $table->timestamps();

            $table->index(['token', 'sampled_at']);
            $table->index('sampled_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_price_samples');
    }
};
