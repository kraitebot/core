<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_systems', function (Blueprint $table) {
            $table->timestamp('cooldown_until')->nullable()->after('timeframes');
        });
    }

    public function down(): void
    {
        Schema::table('api_systems', function (Blueprint $table) {
            $table->dropColumn('cooldown_until');
        });
    }
};
