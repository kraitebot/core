<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Splits the single `max_position_percentage` sizing knob into direction-
 * aware `margin_percentage_long` + `margin_percentage_short`. Also drops
 * the stale `market_order_margin_percentage_long/short` columns that
 * no live code reads (verified via grep before migration authored).
 *
 * Upgrade path:
 *   1. Add two new direction columns, both default 5.00 (matches prior
 *      account-level `max_position_percentage` default).
 *   2. Copy each existing `max_position_percentage` value onto both new
 *      columns so nothing moves for current accounts — the split is
 *      value-preserving on the day of the cut-over.
 *   3. Drop `max_position_percentage` + the two stale
 *      `market_order_margin_percentage_*` columns.
 *
 * Reasoning: sizing needs to diverge per direction for risk-asymmetric
 * books (different leverage already applies per side; margin % should
 * follow). The single-knob model forced identical sizing for LONG and
 * SHORT, which the trading desk will tune independently going forward.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            if (! Schema::hasColumn('accounts', 'margin_percentage_long')) {
                $table->decimal('margin_percentage_long', 5, 2)
                    ->default(5.00)
                    ->after('position_leverage_long');
            }
            if (! Schema::hasColumn('accounts', 'margin_percentage_short')) {
                $table->decimal('margin_percentage_short', 5, 2)
                    ->default(5.00)
                    ->after('position_leverage_short');
            }
        });

        // Copy the current per-account value onto both new columns. If
        // max_position_percentage doesn't exist (fresh install), the
        // COALESCE falls back to 5.00 so defaults line up.
        if (Schema::hasColumn('accounts', 'max_position_percentage')) {
            DB::statement('UPDATE accounts SET margin_percentage_long = COALESCE(max_position_percentage, 5.00), margin_percentage_short = COALESCE(max_position_percentage, 5.00)');
        }

        Schema::table('accounts', function (Blueprint $table): void {
            foreach (['max_position_percentage', 'market_order_margin_percentage_long', 'market_order_margin_percentage_short'] as $stale) {
                if (Schema::hasColumn('accounts', $stale)) {
                    $table->dropColumn($stale);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            if (! Schema::hasColumn('accounts', 'max_position_percentage')) {
                $table->decimal('max_position_percentage', 5, 2)
                    ->default(5.00)
                    ->after('position_leverage_long');
            }
            if (! Schema::hasColumn('accounts', 'market_order_margin_percentage_long')) {
                $table->decimal('market_order_margin_percentage_long', 5, 2)->default(0.42);
            }
            if (! Schema::hasColumn('accounts', 'market_order_margin_percentage_short')) {
                $table->decimal('market_order_margin_percentage_short', 5, 2)->default(0.37);
            }
        });

        if (Schema::hasColumn('accounts', 'margin_percentage_long')) {
            DB::statement('UPDATE accounts SET max_position_percentage = margin_percentage_long');
        }

        Schema::table('accounts', function (Blueprint $table): void {
            foreach (['margin_percentage_long', 'margin_percentage_short'] as $col) {
                if (Schema::hasColumn('accounts', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
