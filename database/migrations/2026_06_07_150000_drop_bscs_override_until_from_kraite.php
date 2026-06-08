<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 — remove the operator override. Critical is now absolute: a
 * BSCS-armed cooldown blocks new opens for every account and can no
 * longer be bypassed. BSCS still computes hourly and the system reacts
 * off the coefficient automatically.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kraite', function (Blueprint $table): void {
            $table->dropColumn(['bscs_override_until', 'bscs_override_reason']);
        });
    }

    public function down(): void
    {
        Schema::table('kraite', function (Blueprint $table): void {
            $table->dateTime('bscs_override_until')
                ->nullable()
                ->after('bscs_freshness_max_seconds');
            $table->string('bscs_override_reason', 255)
                ->nullable()
                ->after('bscs_override_until');
        });
    }
};
