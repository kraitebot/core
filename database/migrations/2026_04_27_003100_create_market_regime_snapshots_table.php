<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Black Swan Composite Score (BSCS) — append-only snapshot table.
 *
 * One row per hourly recompute. Carries the integer score, the band, and
 * the per-sub-signal raw + fired columns so the admin dashboard can chart
 * regime trajectory and an operator can audit any score retroactively.
 *
 * Phase 1 spec — see `~/docs/kraite/black-swan-logic.md`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_regime_snapshots', function (Blueprint $table): void {
            $table->id();

            $table->dateTime('computed_at')
                ->comment('Tick timestamp (top of the hour) when the score was computed');

            $table->unsignedTinyInteger('bscs_score')
                ->comment('Black Swan Composite Score, 0-100 (sum of fired sub-signals × 20)');

            $table->string('bscs_band', 16)
                ->comment('Regime band derived from score: calm / elevated / fragile / critical');

            $table->decimal('vol_expansion_value', 8, 4)
                ->comment('Sub-signal #1 raw value: stdev(BTC 24h returns) / stdev(BTC 14d returns)');
            $table->boolean('vol_expansion_fired')
                ->comment('Sub-signal #1 thresholded: true when ratio > 1.30');

            $table->decimal('range_blowout_value', 8, 4)
                ->comment('Sub-signal #2 raw value: max(24h hi-lo/lo) / mean(14d hi-lo/lo)');
            $table->boolean('range_blowout_fired')
                ->comment('Sub-signal #2 thresholded: true when ratio > 1.50');

            $table->decimal('corr_regime_value', 6, 4)
                ->comment('Sub-signal #3 raw value: mean off-diagonal Pearson corr across BTC + 4 alts (48h)');
            $table->boolean('corr_regime_fired')
                ->comment('Sub-signal #3 thresholded: true when corr > 0.70');

            $table->decimal('rejection_pct_value', 6, 2)
                ->comment('Sub-signal #4 raw value: signed % distance of BTC last close from 14d high');
            $table->boolean('rejection_pct_fired')
                ->comment('Sub-signal #4 thresholded: true when value < -5.0%');

            $table->decimal('fut_vol_value', 8, 4)
                ->comment('Sub-signal #5 raw value: BTC last-24h volume / 14d daily-average volume');
            $table->boolean('fut_vol_fired')
                ->comment('Sub-signal #5 thresholded: true when ratio > 1.20');

            $table->decimal('btc_close', 20, 8)
                ->comment('BTC close at compute time — context column for charts');

            $table->json('inputs_meta')
                ->comment('Symbol set, baseline window, thresholds active at compute time (forensic reproducibility when thresholds get tuned)');

            $table->timestamps();

            $table->index('computed_at');
            $table->index('bscs_score');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_regime_snapshots');
    }
};
