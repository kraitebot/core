<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kraite', function (Blueprint $table): void {
            // System-driven cooldown gate. When `kraite:cron-analyse-bscs`
            // observes a score at or above the cooldown threshold, it sets
            // this column to now() + cooldown_hours. While the timestamp is
            // in the future, `BlackSwanIndex::shouldBlockOpens()` returns
            // true and `HasTradingGuards::canOpenPositions()` blocks new
            // opens. After expiry, the next analyse run re-checks the
            // score and either re-arms (still high → another window) or
            // releases (score below threshold → cooldown ends). Distinct
            // from `bscs_override_until` which is the OPERATOR escape
            // hatch in the opposite direction.
            $table->dateTime('bscs_cooldown_until')
                ->nullable()
                ->default(null)
                ->after('bscs_override_reason')
                ->comment('System-driven block timestamp — opens paused while this is in the future. Set by AnalyseBscsJob when score crosses cooldown threshold.');
        });
    }

    public function down(): void
    {
        Schema::table('kraite', function (Blueprint $table): void {
            $table->dropColumn('bscs_cooldown_until');
        });
    }
};
