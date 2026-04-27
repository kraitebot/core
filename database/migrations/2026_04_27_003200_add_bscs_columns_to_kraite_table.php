<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Denormalised BSCS columns on the `kraite` singleton.
 *
 * `HasTradingGuards::canOpenPositions()` runs on every position-opening
 * tick. Reading the latest score from the singleton is a single-row read
 * with no join — keeps the existing call shape unchanged. Phase 1 only
 * populates these columns; Phase 2 wires them into the gate.
 *
 * Phase 1 spec — see `~/docs/kraite/black-swan-logic.md`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kraite', function (Blueprint $table): void {
            $table->unsignedTinyInteger('bscs_score')
                ->nullable()
                ->after('is_cooling_down')
                ->comment('Latest computed BSCS (0-100) — denormalised for fast read on every cron-create-positions tick');

            $table->string('bscs_band', 16)
                ->nullable()
                ->after('bscs_score')
                ->comment('Latest band: calm / elevated / fragile / critical');

            $table->dateTime('bscs_synced_at')
                ->nullable()
                ->after('bscs_band')
                ->comment('Last successful compute timestamp — staleness gate uses this');

            $table->boolean('bscs_block_active')
                ->default(false)
                ->after('bscs_synced_at')
                ->comment('Derived flag: true when band=critical AND synced_at is fresh AND no active override (Phase 2 wires this into the gate)');

            $table->unsignedTinyInteger('bscs_block_threshold')
                ->default(80)
                ->after('bscs_block_active')
                ->comment('Score at or above which the gate trips — kept on the row so an operator can override per-environment without redeploy');

            $table->unsignedInteger('bscs_freshness_max_seconds')
                ->default(5400)
                ->after('bscs_block_threshold')
                ->comment('Max age of bscs_synced_at before the gate fail-opens (default 90min — one-and-a-half hours of grace for a missed cron)');

            $table->dateTime('bscs_override_until')
                ->nullable()
                ->after('bscs_freshness_max_seconds')
                ->comment('When in the future, force bscs_block_active to false until that timestamp (operator manual escape hatch)');

            $table->string('bscs_override_reason', 255)
                ->nullable()
                ->after('bscs_override_until')
                ->comment('Free-text reason captured when override engaged (audit trail)');
        });
    }

    public function down(): void
    {
        Schema::table('kraite', function (Blueprint $table): void {
            $table->dropColumn([
                'bscs_score',
                'bscs_band',
                'bscs_synced_at',
                'bscs_block_active',
                'bscs_block_threshold',
                'bscs_freshness_max_seconds',
                'bscs_override_until',
                'bscs_override_reason',
            ]);
        });
    }
};
