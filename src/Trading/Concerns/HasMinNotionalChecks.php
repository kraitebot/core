<?php

declare(strict_types=1);

namespace Kraite\Core\Trading\Concerns;

use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Support\Math;

/**
 * Trait HasMinNotionalChecks
 *
 * Provides static methods for checking minimum order requirements across exchanges.
 *
 * Exchange-specific calculations:
 * - Binance/Bybit/BitGet: Direct min_notional field
 * - KuCoin: kucoin_lot_size * kucoin_multiplier * current_price
 */
trait HasMinNotionalChecks
{
    /**
     * Check if an amount meets the minimum notional requirement for a symbol.
     *
     * @param  ExchangeSymbol  $symbol  The exchange symbol to check against
     * @param  string|float  $amount  The amount to verify
     * @return bool True if amount meets minimum, false otherwise
     */
    public static function meetsMinNotional(ExchangeSymbol $symbol, string|float $amount): bool
    {
        $minNotional = self::getEffectiveMinNotional($symbol);

        if ($minNotional === null) {
            return false;
        }

        return Math::gte($amount, $minNotional);
    }

    /**
     * Check if a symbol has all required data to calculate minimum order requirements.
     *
     * @param  ExchangeSymbol  $symbol  The exchange symbol to check
     * @return bool True if symbol has complete min order data
     */
    public static function hasMinOrderRequirements(ExchangeSymbol $symbol): bool
    {
        return self::getEffectiveMinNotional($symbol) !== null;
    }

    /**
     * Calculate the effective minimum notional for a symbol based on exchange type.
     *
     * Uses last_known_price (without freshness check) for KuCoin calculations
     * because we only need a price estimate for min order validation, not real-time accuracy.
     *
     * @param  ExchangeSymbol  $symbol  The exchange symbol
     * @return float|null The minimum notional value, or null if cannot be calculated
     */
    public static function getEffectiveMinNotional(ExchangeSymbol $symbol): ?float
    {
        // Priority 1: Direct min_notional (Binance, Bybit, BitGet)
        if (filled($symbol->min_notional)) {
            return (float) $symbol->min_notional;
        }

        // Priority 2: KuCoin (lot_size * multiplier * price)
        if (filled($symbol->kucoin_lot_size) && filled($symbol->kucoin_multiplier)) {
            $price = $symbol->last_known_price;
            if (! filled($price)) {
                return null;
            }

            $contractValue = Math::mul($symbol->kucoin_lot_size, $symbol->kucoin_multiplier);

            return (float) Math::mul($contractValue, $price);
        }

        return null;
    }
}
