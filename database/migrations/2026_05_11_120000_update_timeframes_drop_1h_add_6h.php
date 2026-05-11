<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Update the global timeframes from ["1h", "4h", "12h", "1d"] to ["4h", "6h", "12h", "1d"].
 *
 * Rationale: 1h is too volatile for direction conclusion — symbols flip LONG/SHORT/INCONCLUSIVE
 * within hours (observed on DOGE, NEAR, CHZ, APT). Starting the walk at 4h gives stabler
 * signals. 6h fills the gap between 4h and 12h (valid TAAPI/Binance interval).
 *
 * Side effects handled:
 *  - Symbols concluded at 1h get their direction/indicators_timeframe/indicators_values
 *    reset so the next conclude cycle re-evaluates them starting at 4h.
 *  - Correlation/elasticity JSON keys for 1h become stale but harmless (the tradeable
 *    scope checks correlation at the concluded timeframe, which will never be 1h again).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Update the kraite singleton timeframes
        DB::table('kraite')->update([
            'timeframes' => json_encode(['4h', '6h', '12h', '1d']),
        ]);

        // Reset symbols that were concluded at 1h — they need re-evaluation
        DB::table('exchange_symbols')
            ->where('indicators_timeframe', '1h')
            ->update([
                'direction' => null,
                'indicators_timeframe' => null,
                'indicators_values' => null,
                'has_invalid_indicator_direction' => false,
                'has_early_direction_change' => false,
                'has_price_trend_misalignment' => false,
            ]);
    }

    public function down(): void
    {
        DB::table('kraite')->update([
            'timeframes' => json_encode(['1h', '4h', '12h', '1d']),
        ]);
    }
};
