<?php

declare(strict_types=1);

namespace Kraite\Core\Support\TradingMappers;

use Kraite\Core\Models\ExchangeSymbol;

/**
 * BybitTradingMapper
 *
 * Exchange-specific trading logic for Bybit.
 */
final class BybitTradingMapper
{
    public function normalizeDeliveryTimestampMs(?int $timestamp): ?int
    {
        return $timestamp !== null && $timestamp > 0 ? $timestamp : null;
    }

    public function missingFromCatalogueIsTerminal(): bool
    {
        return false;
    }

    /**
     * Determine if an exchange symbol is now being delisted.
     *
     * Bybit logic:
     * - Perpetuals have no delivery date (null) by default
     * - When delivery_ts_ms is set (null → value), the perpetual is being delisted
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
