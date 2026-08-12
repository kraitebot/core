<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $timestamp = now();

        DB::table('notifications')
            ->whereIn('canonical', ['position_closed', 'position_wap_applied'])
            ->update([
                'is_active' => false,
                'updated_at' => $timestamp,
            ]);

        DB::table('notifications')
            ->where('canonical', 'position_high_profit_closed')
            ->update([
                'description' => 'App-only close alert after a position that reached its penultimate DCA limit receives final exchange PnL.',
                'updated_at' => $timestamp,
            ]);

        DB::table('notifications')->updateOrInsert(
            ['canonical' => 'position_penultimate_limit_filled'],
            [
                'title' => 'Penultimate Limit Filled',
                'description' => 'App-only alert when a position fills at least max limit orders minus one.',
                'detailed_description' => 'Sent once to the trader app when the position reaches its penultimate DCA limit. Routine earlier WAP recalculations remain silent.',
                'usage_reference' => 'Jobs/Atomic/Order/CalculateWapAndModifyProfitOrderJob',
                'default_severity' => 'high',
                'verified' => true,
                'cache_duration' => 30,
                'cache_key' => json_encode(['position']),
                'is_active' => true,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
        );
    }

    public function down(): void
    {
        $timestamp = now();

        DB::table('notifications')
            ->where('canonical', 'position_penultimate_limit_filled')
            ->delete();

        DB::table('notifications')
            ->whereIn('canonical', ['position_closed', 'position_wap_applied'])
            ->update([
                'is_active' => true,
                'updated_at' => $timestamp,
            ]);

        DB::table('notifications')
            ->where('canonical', 'position_high_profit_closed')
            ->update([
                'description' => 'Celebratory ping after a WAP\'ed closed position receives final exchange PnL and filled >= total_limit_orders_filled_to_notify (full ladder took).',
                'updated_at' => $timestamp,
            ]);
    }
};
