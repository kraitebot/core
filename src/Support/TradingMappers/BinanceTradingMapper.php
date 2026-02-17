<?php

declare(strict_types=1);

namespace Kraite\Core\Support\TradingMappers;

use Kraite\Core\Models\ExchangeSymbol;

/**
 * BinanceTradingMapper
 *
 * Exchange-specific trading logic for Binance.
 */
final class BinanceTradingMapper
{
    /**
     * Binance perpetual default delivery date: Dec 25, 2100.
     * Any other value indicates a contract rollover/delisting.
     */
    public const PERPETUAL_DEFAULT = 4133404800000;

    /**
     * Determine if an exchange symbol is now being delisted.
     *
     * Binance logic:
     * - Perpetuals have a default delivery_ts_ms of 4133404800000 (Dec 25, 2100)
     * - Any other value indicates delisting/settlement scheduled
     * - Notify on first sync if symbol comes already delisted (null → real date)
     * - Notify when changed from perpetual default or another date to a real date
     */
    public function isNowDelisted(ExchangeSymbol $exchangeSymbol): bool
    {
        if (! $exchangeSymbol->wasChanged('delivery_ts_ms')) {
            return false;
        }

        $oldValue = $exchangeSymbol->getOriginal('delivery_ts_ms');
        $newValue = $exchangeSymbol->delivery_ts_ms;

        // Ignore perpetual default value - this is normal state
        if ($newValue === self::PERPETUAL_DEFAULT) {
            return false;
        }

        // Notify when:
        // 1. First sync with already delisted symbol (null → real date, not perpetual default)
        // 2. Changed from perpetual default to real date
        // 3. Changed from one real date to another
        return $newValue !== null;
    }
}
