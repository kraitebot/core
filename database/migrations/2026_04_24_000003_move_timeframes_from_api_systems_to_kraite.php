<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Move the indicator/kline timeframe list from `api_systems.timeframes`
 * (per-exchange) to `kraite.timeframes` (global singleton).
 *
 * Every consumer (FetchKlinesCommand, ConcludeSymbolsDirectionCommand,
 * HasTokenDiscovery, CalculateBtcCorrelationJob, CalculateBtcElasticityJob)
 * reads the same timeframe list regardless of which exchange it's acting
 * on — the per-exchange optionality introduced by the 2026-01-07 migration
 * was never exploited in practice (all four exchange rows carry identical
 * values). Consolidating to a single source of truth removes the
 * "which api_system row do I need to update?" confusion and aligns with
 * the rest of the kraite-singleton-controlled global config
 * (allow_opening_positions, is_cooling_down, notification_channels).
 *
 * Up: add kraite.timeframes JSON, hydrate it from the first non-null
 * api_systems.timeframes row (falls back to the canonical
 * ["1h","4h","12h","1d"] set if none exists yet), drop the column from
 * api_systems.
 *
 * Down: add api_systems.timeframes back, replicate kraite.timeframes
 * into every is_exchange row, drop kraite.timeframes.
 */
return new class extends Migration
{
    private const DEFAULT_TIMEFRAMES = ['1h', '4h', '12h', '1d'];

    public function up(): void
    {
        if (! Schema::hasColumn('kraite', 'timeframes')) {
            Schema::table('kraite', function (Blueprint $table): void {
                $table->json('timeframes')
                    ->nullable()
                    ->after('notification_channels')
                    ->comment('Global kline/indicator timeframe list used by fetch + conclude + correlation pipelines.');
            });
        }

        $sourceValue = Schema::hasColumn('api_systems', 'timeframes')
            ? DB::table('api_systems')->whereNotNull('timeframes')->value('timeframes')
            : null;

        $timeframes = $sourceValue !== null
            ? json_decode($sourceValue, true)
            : self::DEFAULT_TIMEFRAMES;

        if (! is_array($timeframes) || empty($timeframes)) {
            $timeframes = self::DEFAULT_TIMEFRAMES;
        }

        if (! in_array('1d', $timeframes, true)) {
            $timeframes[] = '1d';
        }

        DB::table('kraite')
            ->where('id', 1)
            ->update(['timeframes' => json_encode(array_values($timeframes))]);

        if (Schema::hasColumn('api_systems', 'timeframes')) {
            Schema::table('api_systems', function (Blueprint $table): void {
                $table->dropColumn('timeframes');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('api_systems', 'timeframes')) {
            Schema::table('api_systems', function (Blueprint $table): void {
                $table->json('timeframes')
                    ->nullable()
                    ->after('websocket_class')
                    ->comment('Supported kline timeframes for this exchange (5m to 1d range)');
            });
        }

        $kraiteValue = Schema::hasColumn('kraite', 'timeframes')
            ? DB::table('kraite')->where('id', 1)->value('timeframes')
            : null;

        $timeframes = $kraiteValue !== null
            ? json_decode($kraiteValue, true)
            : self::DEFAULT_TIMEFRAMES;

        if (! is_array($timeframes) || empty($timeframes)) {
            $timeframes = self::DEFAULT_TIMEFRAMES;
        }

        DB::table('api_systems')
            ->where('is_exchange', true)
            ->update(['timeframes' => json_encode(array_values($timeframes))]);

        if (Schema::hasColumn('kraite', 'timeframes')) {
            Schema::table('kraite', function (Blueprint $table): void {
                $table->dropColumn('timeframes');
            });
        }
    }
};
