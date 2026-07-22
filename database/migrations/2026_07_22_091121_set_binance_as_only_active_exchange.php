<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('api_systems')
            ->where('is_exchange', true)
            ->update(['is_active' => false]);

        DB::table('api_systems')
            ->where('is_exchange', false)
            ->update(['is_active' => true]);

        DB::table('api_systems')
            ->where('canonical', 'binance')
            ->update(['is_active' => true]);
    }

    public function down(): void
    {
        DB::table('api_systems')
            ->whereIn('canonical', ['binance', 'bybit', 'kucoin', 'bitget'])
            ->update(['is_active' => true]);
    }
};
