<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records how far back the exchange income ledger is authoritative for an
 * account.
 *
 * Daily figures prefer the ledger, because it books each fee and fill on the
 * day the exchange charged it. Older windows have to fall back to close-day
 * position grouping, and something has to decide where that line sits.
 *
 * Inferring it from the earliest stored record would be wrong in a quiet
 * period: a ledger whose first entry is Tuesday evening genuinely covers
 * Tuesday morning too, there was simply nothing to book. Only the sync knows
 * which window it actually asked the exchange for, so the sync writes it here.
 *
 * Null means "no ledger yet" — every window falls back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dateTime('incomes_synced_from')
                ->nullable()
                ->comment('Earliest instant the exchange income ledger is complete from, UTC.');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropColumn('incomes_synced_from');
        });
    }
};
