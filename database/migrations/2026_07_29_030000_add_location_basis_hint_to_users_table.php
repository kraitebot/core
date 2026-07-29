<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remembers where a trader was last seen, and which country we last offered a
 * trading-day-basis change for.
 *
 * The basis exists to match the trader's exchange, not their address — a
 * trader in Lisbon whose Binance rolls at UTC+2 wants UTC+2, and silently
 * following their location would put the two products back out of step. So
 * moving country never changes anything on its own: it offers, once.
 *
 * Two columns because they answer different questions. `last_seen_country`
 * is where they are now, which changes whenever they travel.
 * `basis_hint_country` is the last country we already asked about, so a
 * declined suggestion stays declined instead of reappearing on every page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('last_seen_country', 2)
                ->nullable()
                ->after('utc_offset_minutes')
                ->comment('ISO-3166 alpha-2 from the CDN edge, last request seen.');

            $table->string('basis_hint_country', 2)
                ->nullable()
                ->after('last_seen_country')
                ->comment('Last country a day-basis suggestion was shown for; stops it repeating.');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['last_seen_country', 'basis_hint_country']);
        });
    }
};
