<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `users.utc_offset_minutes` — the trader's day basis, i.e. the hour at
 * which their trading day rolls over.
 *
 * Exchanges expose exactly this setting: Binance calls it "Change Basis" and
 * stores a fixed UTC offset (`UTC+2, 00:00`), so its "Today's PnL" resets at
 * that offset's midnight. Kraite reported every trader's day in UTC, so on
 * 2026-07-29 at 23:38 UTC the dashboard totalled a full UTC day (+$4.72)
 * while Binance had been counting its own day for 1h38m. Same trades, two
 * different questions.
 *
 * Minutes rather than a named zone, deliberately:
 *  - it mirrors what the exchange itself stores, which is the entire point;
 *  - the production MySQL host has no timezone tables loaded, so `CONVERT_TZ`
 *    returns NULL there while a fixed-minute shift always works.
 *
 * Stored timestamps remain UTC everywhere. This only decides which calendar
 * day an instant is reported under. Default 0 keeps every existing trader on
 * the UTC basis they already had, so no historical figure moves until someone
 * chooses otherwise on their profile.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->smallInteger('utc_offset_minutes')
                ->default(0)
                ->after('email')
                ->comment('Trading-day basis in minutes from UTC (-720..840), mirroring the exchange Change Basis setting.');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('utc_offset_minutes');
        });
    }
};
