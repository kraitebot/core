<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 — snapshot the BSCS regime each position was opened under.
 *
 *   bscs_band  — band + direction at open, e.g. "elevated-long" (nullable;
 *                null when the regime hasn't computed yet).
 *   bscs_score — the raw 0-100 BSCS score at open, for finer analysis of
 *                how positions born in each regime perform.
 *
 * Populated in AssignBestTokensToPositionSlotsJob when the slot is created.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table): void {
            $table->string('bscs_band')->nullable()->after('leverage');
            $table->unsignedTinyInteger('bscs_score')->nullable()->after('bscs_band');
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table): void {
            $table->dropColumn(['bscs_band', 'bscs_score']);
        });
    }
};
