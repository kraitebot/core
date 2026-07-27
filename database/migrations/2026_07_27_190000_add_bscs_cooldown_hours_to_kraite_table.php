<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Runtime override for the BSCS score-cooldown window (the long gate a
 * critical composite score arms — not the fast shock breaker's short
 * pause). Follows the singleton runtime-settings pattern: the column
 * wins when set, NULL inherits the config default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kraite', function (Blueprint $table): void {
            $table->unsignedSmallInteger('bscs_cooldown_hours')
                ->nullable()
                ->after('bscs_block_threshold');
        });
    }

    public function down(): void
    {
        Schema::table('kraite', function (Blueprint $table): void {
            $table->dropColumn('bscs_cooldown_hours');
        });
    }
};
