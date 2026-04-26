<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `accounts.on_hedge_mode` to support both Binance Futures position
 * modes — Hedge (`dualSidePosition=true`) and One-Way (`dualSidePosition=
 * false`). Universal flag; per-exchange mappers translate the underlying
 * setting (Bybit's `positionIdx`, Kucoin's mode flag, etc.) when those
 * exchanges become load-bearing.
 *
 * Default TRUE preserves the historical assumption (existing live accounts
 * including Main Binance Account #1 are in hedge mode). New rows created
 * by AccountFactory / BusinessSeeder default to FALSE (one-way) at the
 * application layer per Bruno's preference, so this DB default only fires
 * for ad-hoc inserts that don't go through the application flow.
 *
 * The flag is auto-corrected reactively when Binance returns a position-
 * side mismatch error (-4060/-4061/-4062/-4067) — the catch lives in
 * HandlesApiJobExceptions and uses lockForUpdate to flip atomically.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->boolean('on_hedge_mode')
                ->default(true)
                ->after('margin_mode')
                ->comment('Binance/Bybit/Kucoin/Bitget position-mode flag. true = hedge (dual position side), false = one-way. Auto-flipped reactively on -4061 / equivalent.');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropColumn('on_hedge_mode');
        });
    }
};
