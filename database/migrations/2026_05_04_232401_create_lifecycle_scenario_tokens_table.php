<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per token selected at T0 of a scenario.
 *
 * The whole point of this table is the `frozen_config` JSON: at the
 * moment the user picks a token, every config value the lifecycle
 * calculator depends on (gap %, ladder size, multipliers, TP %, SL %,
 * leverage, margin per position, base quantity) is snapshotted here
 * and never re-read from the live `exchange_symbols` / `accounts`
 * tables again. That way the scenario stays reproducible even if the
 * operator tunes ladder gaps two weeks later.
 *
 * `entry_price` is the user-provided market entry at T0. The four
 * limit prices are derived from entry_price + frozen gap % and live
 * in computed state, not in the row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lifecycle_scenario_tokens', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('scenario_id')
                ->constrained('lifecycle_scenarios')
                ->restrictOnDelete();

            // Soft reference to exchange_symbols. Display-only — once
            // frozen_config is captured, the calculator never needs to
            // re-read the live row.
            $table->unsignedBigInteger('exchange_symbol_id');

            $table->string('token_label', 50);

            $table->decimal('entry_price', 30, 12);

            // Display order in the grid (0..N-1). Lets the user reorder
            // tokens without renumbering primary keys.
            $table->unsignedInteger('display_order')->default(0);

            // The whole snapshot:
            //   - percentage_gap (resolved long/short at scenario side)
            //   - total_limit_orders
            //   - limit_quantity_multipliers (array)
            //   - profit_percentage (TP %)
            //   - stop_market_percentage (SL %)
            //   - leverage (from account, resolved by side)
            //   - margin_per_position_usdt (computed from account margin × margin_percentage)
            //   - base_quantity (computed from margin_per_position × leverage / entry_price)
            //   - price_precision, quantity_precision (from exchange_symbol)
            $table->json('frozen_config');

            $table->timestamps();

            $table->index('scenario_id');
            $table->index('exchange_symbol_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lifecycle_scenario_tokens');
    }
};
