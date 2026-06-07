<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-account opt-out of the global BSCS / BlackSwan open-suspension gate.
 * Default true preserves current behaviour (every account honours the
 * cooldown). Set false to let an account keep opening positions even while
 * BSCS is blocking opens fleet-wide — it still obeys the master kill
 * (kraite.can_trade) and allow_opening_positions; only the BSCS gate is
 * bypassed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->boolean('respect_bscs')
                ->default(true)
                ->after('use_btc_bias_restriction')
                ->comment('When true (default) the account honours the global BSCS/BlackSwan open-suspension cooldown in HasTradingGuards::canOpenPositions(). When false the account ignores that gate and opens positions even while BSCS is suspending opens — the master kill (kraite.can_trade) and allow_opening_positions still apply.');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropColumn('respect_bscs');
        });
    }
};
