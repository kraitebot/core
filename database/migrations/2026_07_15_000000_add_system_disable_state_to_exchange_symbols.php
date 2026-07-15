<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exchange_symbols', function (Blueprint $table): void {
            $table->timestamp('system_disabled_at')
                ->nullable()
                ->after('is_manually_enabled')
                ->index()
                ->comment('Automatic trading-selection block; independent from the sysadmin-owned manual flag');
            $table->string('system_disabled_reason', 64)
                ->nullable()
                ->after('system_disabled_at');
        });
    }

    public function down(): void
    {
        Schema::table('exchange_symbols', function (Blueprint $table): void {
            $table->dropIndex(['system_disabled_at']);
            $table->dropColumn(['system_disabled_at', 'system_disabled_reason']);
        });
    }
};
