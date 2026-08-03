<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PREVIOUS_TIMEFRAMES = ['1h', '4h', '12h', '1d'];

    private const SUPPORTED_TIMEFRAMES = ['1h', '4h', '1d'];

    public function up(): void
    {
        DB::table('kraite')
            ->where('id', 1)
            ->update([
                'timeframes' => json_encode(self::SUPPORTED_TIMEFRAMES, JSON_THROW_ON_ERROR),
            ]);

        DB::table('exchange_symbols')
            ->where('indicators_timeframe', '12h')
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
        DB::table('kraite')
            ->where('id', 1)
            ->update([
                'timeframes' => json_encode(self::PREVIOUS_TIMEFRAMES, JSON_THROW_ON_ERROR),
            ]);
    }
};
