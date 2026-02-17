<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\Order\Binance;

use Kraite\Core\Jobs\Atomic\Order\CalculateWapAndModifyProfitOrderJob as BaseCalculateWapAndModifyProfitOrderJob;

/**
 * CalculateWapAndModifyProfitOrderJob (Atomic) - Binance
 *
 * Binance-specific implementation for WAP calculation and profit order modification.
 * Currently identical to base implementation but allows for exchange-specific
 * overrides in the future (e.g., different position response format, hedging mode).
 *
 * Binance Futures position response includes:
 * - symbol: e.g. "BTCUSDT"
 * - positionAmt: position size (negative for SHORT)
 * - breakEvenPrice: weighted average entry price
 * - positionSide: "LONG", "SHORT", or "BOTH" (hedge mode)
 */
final class CalculateWapAndModifyProfitOrderJob extends BaseCalculateWapAndModifyProfitOrderJob
{
    // Uses base implementation
    // Override methods here for Binance-specific behavior if needed
}
