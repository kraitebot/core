<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('kraite', function (Blueprint $table): void {
            $table->string('bscs_cooldown_source', 32)
                ->nullable()
                ->after('bscs_cooldown_until')
                ->comment('Breaker that armed the shared cooldown: bscs or market_shock.');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('kraite', function (Blueprint $table): void {
            $table->dropColumn('bscs_cooldown_source');
        });
    }
};
