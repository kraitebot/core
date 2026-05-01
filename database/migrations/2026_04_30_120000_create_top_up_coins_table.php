<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('top_up_coins', function (Blueprint $table) {
            $table->id();

            $table->string('canonical', 64)
                ->unique()
                ->comment('NOWPayments currency code, e.g. usdttrc20, btc, eth, bnbbsc.');

            $table->string('display_name', 128)
                ->comment('Human-readable label rendered in the user dropdown, e.g. "Tether (Tron)".');

            $table->unsignedSmallInteger('sort_order')
                ->default(100)
                ->comment('Lower values render first in the dropdown.');

            $table->boolean('is_active')
                ->default(true)
                ->comment('Inactive coins are hidden from the user dropdown but keep their config for re-activation.');

            $table->decimal('min_amount_override', 14, 6)
                ->nullable()
                ->comment('Optional admin override of the gateway-derived minimum. NULL = always fetch live from NOWPayments /min-amount.');

            $table->timestamps();
        });

        DB::table('top_up_coins')->insert([
            ['canonical' => 'usdttrc20', 'display_name' => 'USDT (Tron)',      'sort_order' => 10,  'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['canonical' => 'usdtbsc',   'display_name' => 'USDT (BSC)',       'sort_order' => 20,  'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['canonical' => 'usdcbsc',   'display_name' => 'USDC (BSC)',       'sort_order' => 30,  'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['canonical' => 'usdcsol',   'display_name' => 'USDC (Solana)',    'sort_order' => 40,  'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['canonical' => 'usdtsol',   'display_name' => 'USDT (Solana)',    'sort_order' => 50,  'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['canonical' => 'btc',       'display_name' => 'BTC (Bitcoin)',    'sort_order' => 60,  'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['canonical' => 'eth',       'display_name' => 'ETH (Ethereum)',   'sort_order' => 70,  'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['canonical' => 'sol',       'display_name' => 'SOL (Solana)',     'sort_order' => 80,  'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['canonical' => 'ltc',       'display_name' => 'LTC (Litecoin)',   'sort_order' => 90,  'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['canonical' => 'bnbbsc',    'display_name' => 'BNB (BSC)',        'sort_order' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('top_up_coins');
    }
};
