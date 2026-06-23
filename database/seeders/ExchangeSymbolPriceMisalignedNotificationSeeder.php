<?php

declare(strict_types=1);

namespace Kraite\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Kraite\Core\Models\Notification;

/**
 * Registers the `exchange_symbol_price_misaligned` notification — fired when the
 * refresh price-alignment check finds a non-Binance exchange symbol whose live
 * price doesn't match its Binance same-asset sibling (a unit-divergent contract,
 * e.g. KuCoin `FLOKI` vs Binance `1000FLOKI`) and switches it off.
 *
 * cache_key keyed on `exchange_symbol_id` throttles to one alert per symbol per
 * window; cache_duration 86400 (1 day) is a backstop on top of the job's own
 * transition guard (it only notifies on the aligned → divergent flip).
 */
final class ExchangeSymbolPriceMisalignedNotificationSeeder extends Seeder
{
    public function run(): void
    {
        Notification::updateOrCreate(
            ['canonical' => 'exchange_symbol_price_misaligned'],
            [
                'canonical' => 'exchange_symbol_price_misaligned',
                'title' => 'Exchange Symbol Price Misaligned',
                'description' => "A non-Binance exchange symbol's live price does not match its Binance same-asset sibling — it lists a different contract unit (e.g. a plain token vs Binance's 1000x contract). The replicated mark_price is wrong by the contract ratio, so the symbol is flagged is_price_aligned=false and switched off (is_manually_enabled=false) to keep it out of trading.",
                'default_severity' => 'high',
                'cache_duration' => 86400,
                'cache_key' => ['exchange_symbol_id'],
                'is_active' => true,
                'verified' => true,
            ],
        );
    }
}
