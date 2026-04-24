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
     *
     * Accepts BOTH the pre-save (`isDirty`) and post-save (`wasChanged`)
     * dirty-state signals. ExchangeSymbolObserver calls this twice per
     * sync — once inside `saving()` so the flag gets persisted with the
     * row, once inside `saved()` to trigger the notification. If we only
     * accepted `wasChanged`, the `saving()` call would return false
     * (because the save hasn't committed yet) and
     * `is_marked_for_delisting` would never land in the DB — the
     * notification still fires from the `saved()` path but the
     * persistent flag silently stays false. This bug triggered on
     * 2026-04-24 for DEGEN / B3 / BOB / ZKJ / DAM / IR: all six got
     * pushover alerts, none got flagged in DB.
     */
    public function isNowDelisted(ExchangeSymbol $exchangeSymbol): bool
    {
        $changed = $exchangeSymbol->isDirty('delivery_ts_ms')
            || $exchangeSymbol->wasChanged('delivery_ts_ms');

        if (! $changed) {
            return false;
        }

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
