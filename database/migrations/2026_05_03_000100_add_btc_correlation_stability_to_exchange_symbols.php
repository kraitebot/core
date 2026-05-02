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
            $table->json('btc_correlation_stability')
                ->nullable()
                ->after('btc_correlation_rolling')
                ->comment('Per-timeframe std-dev of the rolling-window correlation series with BTC. Lower = more reliable signal, used by CorrelationStabilityWeight to downweight jittery candidates during slot selection.');
        });
    }

    public function down(): void
    {
        Schema::table('exchange_symbols', function (Blueprint $table): void {
            $table->dropColumn('btc_correlation_stability');
        });
    }
};
