<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kraite', function (Blueprint $table) {
            $table->decimal('top_up_minimum_when_covered_usdt', 12, 4)
                ->default(20)
                ->comment('Flat USDT floor for top-ups when the user wallet already covers the next renewal. Below this the form rejects micro chip-ins. Bypassed when the user is under-funded — in that case the floor is the shortfall to cover the next renewal.');
        });
    }

    public function down(): void
    {
        Schema::table('kraite', function (Blueprint $table) {
            $table->dropColumn('top_up_minimum_when_covered_usdt');
        });
    }
};
