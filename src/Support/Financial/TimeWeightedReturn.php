<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Financial;

/**
 * Geometric linking of daily return rates into one window return —
 * the industry-standard time-weighted return (TWR).
 *
 * Why TWR instead of `windowPnl / startWallet`: the simple ratio uses
 * one frozen denominator, so any deposit or withdrawal mid-window
 * silently distorts it. Money added on day 12 earns real PnL from day
 * 13 onwards, but the ratio keeps dividing by the pre-deposit wallet
 * and reports a return the trader never delivered. TWR sidesteps that
 * by scoring each day against the capital that day actually traded and
 * chaining the results, so cash flow moves the euros but never the
 * percentage.
 */
final class TimeWeightedReturn
{
    /**
     * Precision for the chained ratio — deliberately higher than the
     * scale of 8 the sibling calculators use for money, so rounding
     * cannot accumulate across a long window's worth of multiplies.
     */
    private const CHAIN_SCALE = 16;

    /**
     * Chain daily rates into a single percentage return. Returns null
     * for an empty series so callers can render "—" instead of a fake
     * "0%".
     *
     * @param  array<string, string>  $dailyRates  Decimal daily rates (0.01 = 1%).
     */
    public static function fromDailyRates(array $dailyRates): ?float
    {
        if ($dailyRates === []) {
            return null;
        }

        $factor = '1';

        foreach ($dailyRates as $rate) {
            $factor = bcmul($factor, bcadd('1', $rate, self::CHAIN_SCALE), self::CHAIN_SCALE);
        }

        return (float) bcmul(bcsub($factor, '1', self::CHAIN_SCALE), '100', 6);
    }
}
