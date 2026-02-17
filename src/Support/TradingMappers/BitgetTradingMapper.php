<?php

declare(strict_types=1);

namespace Kraite\Core\Support\TradingMappers;

use Kraite\Core\Models\ExchangeSymbol;

/**
 * BitgetTradingMapper
 *
 * Exchange-specific trading logic for BitGet.
 */
final class BitgetTradingMapper
{
    /**
     * Determine if an exchange symbol is now being delisted.
     *
     * BitGet logic:
     * - Perpetuals (symbolType = perpetual) have deliveryTime empty by default
     * - When deliveryTime appears (null → value), the perpetual is being delisted
     * - Also notify on delivery date changes (rare but possible)
     */
    public function isNowDelisted(ExchangeSymbol $exchangeSymbol): bool
    {
        if (! $exchangeSymbol->wasChanged('delivery_ts_ms')) {
            return false;
        }

        $oldValue = $exchangeSymbol->getOriginal('delivery_ts_ms');
        $newValue = $exchangeSymbol->delivery_ts_ms;

        // Notify when: null → value OR value → different value
        return ($oldValue === null && $newValue !== null)
            || ($oldValue !== null && $newValue !== null && $oldValue !== $newValue);
    }
}
