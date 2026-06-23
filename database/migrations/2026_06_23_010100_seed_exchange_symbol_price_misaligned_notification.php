<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Kraite\Core\Database\Seeders\ExchangeSymbolPriceMisalignedNotificationSeeder;
use Kraite\Core\Models\Notification;

/**
 * Seed the `exchange_symbol_price_misaligned` notification row — fired once per
 * symbol when the refresh price-alignment check disables a unit-divergent
 * cross-exchange contract.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new ExchangeSymbolPriceMisalignedNotificationSeeder)->run();
    }

    public function down(): void
    {
        Notification::where('canonical', 'exchange_symbol_price_misaligned')->delete();
    }
};
