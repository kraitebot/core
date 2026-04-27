<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cron stamps `bscs_synced_at` at xx:00 (the snapshot's computed_at)
        // but doesn't run until xx:50 of the NEXT hour. So the in-DB synced_at
        // is naturally 60-110 minutes old between consecutive successful runs.
        // The original 5400s (90 min) ceiling left only 30 min of margin — a
        // single skipped tick or 5-min schedule drift would trip fail-open
        // even on a healthy pipeline. 6900s (115 min) gives 25 min cushion
        // past the worst-case 110-min interval, which still trips fail-open
        // promptly for genuinely stale signals (one missed-and-recovered
        // tick = ~115 min = right at the threshold).
        Schema::table('kraite', function (Blueprint $table): void {
            $table->unsignedInteger('bscs_freshness_max_seconds')
                ->default(6900)
                ->change();
        });

        // Bump existing rows that still hold the old default so prod picks
        // the new ceiling up immediately. Operators that already tuned to a
        // non-5400 value are left alone (their explicit choice survives).
        DB::table('kraite')
            ->where('bscs_freshness_max_seconds', 5400)
            ->update(['bscs_freshness_max_seconds' => 6900]);
    }

    public function down(): void
    {
        Schema::table('kraite', function (Blueprint $table): void {
            $table->unsignedInteger('bscs_freshness_max_seconds')
                ->default(5400)
                ->change();
        });

        DB::table('kraite')
            ->where('bscs_freshness_max_seconds', 6900)
            ->update(['bscs_freshness_max_seconds' => 5400]);
    }
};
