<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 — remove the per-account BSCS opt-out. With the regime leverage
 * + count ramps applied globally and Critical being absolute, an account
 * can no longer choose to ignore the BSCS open-suspension. The cooldown
 * binds every account uniformly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropColumn('respect_bscs');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->boolean('respect_bscs')
                ->default(true)
                ->after('use_btc_bias_restriction');
        });
    }
};
