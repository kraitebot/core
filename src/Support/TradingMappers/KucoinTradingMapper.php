<?php

declare(strict_types=1);

namespace Kraite\Core\Support\TradingMappers;

use Kraite\Core\Models\ExchangeSymbol;

/**
 * KucoinTradingMapper
 *
 * Exchange-specific trading logic for KuCoin.
 */
final class KucoinTradingMapper
{
    /**
     * Determine if an exchange symbol is now being delisted.
     *
     * KuCoin logic:
     * - Perpetuals (suffix M) have expireDate = null by default
     * - When expireDate appears (null → value), the perpetual is being delisted
     * - Also notify on delivery date changes (rare but possible)
     *
     * See BinanceTradingMapper::isNowDelisted() for the rationale behind
     * accepting both `isDirty` (pre-save / saving hook) and `wasChanged`
     * (post-save / saved hook) signals — same bug class, same fix shape.
     */
    public function isNowDelisted(ExchangeSymbol $exchangeSymbol): bool
    {
        $changed = $exchangeSymbol->isDirty('delivery_ts_ms')
            || $exchangeSymbol->wasChanged('delivery_ts_ms');

        if (! $changed) {
            return false;
        }

        return $exchangeSymbol->delivery_ts_ms !== null;
    }
}
