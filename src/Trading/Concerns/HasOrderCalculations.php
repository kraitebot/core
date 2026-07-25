<?php

declare(strict_types=1);

namespace Kraite\Core\Trading\Concerns;

use InvalidArgumentException;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Support\Math;
use Kraite\Core\Trading\Kraite;
use RuntimeException;

trait HasOrderCalculations
{
    /**
     * Calculates budget distribution across market order and limit ladder.
     *
     * NOT THE PRODUCTION SIZING PATH. This is the bounded-budget martingale
     * alternative — it splits a fixed total `$budget` across one MARKET leg
     * and N LIMIT rungs so the cumulative margin sums exactly to budget.
     *
     * Live opening uses the UNBOUNDED ladder model instead:
     *   - `Kraite::calculateMarketOrderData()` for the MARKET leg with the
     *     external `get_market_order_amount_divider($N)` divider applied
     *     by the caller (`PlaceMarketOrderJob`, `VerifyOrderNotionalForMarketOrderJob`).
     *   - `Kraite::calculateLimitOrdersData()` chains rung quantities from
     *     the MARKET quantity using step ratios — no fixed budget cap.
     *
     * Preserved here as a reference implementation of the bounded-budget
     * model and for potential future strategies. Has zero production
     * callers today; only consumed by `CalculateBudgetDistributionTest`.
     *
     * Algorithm:
     * 1. Compute weight sum S using inverse cumulative products from the end:
     *    S = 1 + 1/m[N-1] + 1/(m[N-1]×m[N-2]) + ... + 1/(m[N-1]×...×m[0])
     * 2. Last limit margin = budget / S
     * 3. Work backwards: each margin[i] = margin[i+1] / multiplier[i]
     *
     * This ensures total margin = budget exactly (martingale property).
     *
     * @param  string|int|float  $budget  Total margin budget for the position
     * @param  array<int, float|int|string>  $multipliers  Multiplier for each step (e.g., [2, 1.5, 2.5, 2])
     * @param  int  $totalLimitOrders  Number of limit orders (N)
     * @return array{
     *   market: string,
     *   limits: array<int, string>,
     *   total: string,
     *   weights: array<int, string>
     * }
     */
    public static function calculateBudgetDistribution(
        $budget,
        array $multipliers,
        int $totalLimitOrders
    ): array {
        $scale = Kraite::SCALE;

        $budgetStr = (string) $budget;
        if (! is_numeric($budgetStr) || Math::lte($budgetStr, '0', $scale)) {
            throw new InvalidArgumentException("Budget must be numeric and > 0 (got: {$budget}).");
        }

        if ($totalLimitOrders < 0) {
            throw new InvalidArgumentException("Total limit orders must be >= 0 (got: {$totalLimitOrders}).");
        }

        if (empty($multipliers)) {
            throw new InvalidArgumentException('Multipliers array cannot be empty.');
        }

        // Validate all multipliers are positive numbers
        foreach ($multipliers as $i => $m) {
            if (! is_numeric((string) $m) || Math::lte((string) $m, '0')) {
                throw new InvalidArgumentException("Multiplier at index {$i} must be a positive number (got: {$m}).");
            }
        }

        $N = $totalLimitOrders;

        // Get effective multipliers for each position (using laddered value logic)
        // Position 0 = market, Position 1..N = limit orders
        // The multiplier at position i is the ratio between position i and position i-1
        $effectiveMultipliers = [];
        for ($i = 0; $i <= $N; $i++) {
            $effectiveMultipliers[$i] = (string) Kraite::returnLadderedValue($multipliers, $i);
        }

        // Compute S = sum of inverse cumulative products from the end
        // S = 1 (for last limit) + 1/m[N] + 1/(m[N]*m[N-1]) + ... + 1/(m[N]*...*m[1])
        // Where m[i] is the multiplier going from position i-1 to position i
        $S = '1'; // Start with 1 for the last limit order
        $inverseCumulativeProduct = '1';

        // Work backwards from position N to position 0 (market)
        // At step i, divide by m[i-1] which is the multiplier from position (i-1) to position i
        for ($i = $N; $i >= 1; $i--) {
            $m = $effectiveMultipliers[$i - 1];
            $inverseCumulativeProduct = Math::div($inverseCumulativeProduct, $m, $scale);
            $S = Math::add($S, $inverseCumulativeProduct, $scale);
        }

        // Last limit margin = budget / S
        $lastLimitMargin = Math::div($budgetStr, $S, $scale);

        // Build margins array working backwards from last limit
        // limits[N-1] = lastLimitMargin (0-indexed, so last is N-1)
        // limits[i] = limits[i+1] / multiplier[i+1]
        // market = limits[0] / multiplier[0]
        $limits = [];
        $weights = [];

        if ($N > 0) {
            $limits[$N - 1] = $lastLimitMargin;
            $weights[$N] = Math::div($lastLimitMargin, $budgetStr, $scale);

            // Fill limits from end to beginning
            // limits[i] = limits[i+1] / m[i+1] where m[i+1] is the multiplier from L(i+1) to L(i+2)
            for ($i = $N - 2; $i >= 0; $i--) {
                $divisor = $effectiveMultipliers[$i + 1];
                $limits[$i] = Math::div($limits[$i + 1], $divisor, $scale);
            }

            // Market margin = first limit / multiplier[0]
            $marketMargin = Math::div($limits[0], $effectiveMultipliers[0], $scale);

            // Calculate weights for all positions
            $weights[0] = Math::div($marketMargin, $budgetStr, $scale);
            for ($i = 0; $i < $N; $i++) {
                $weights[$i + 1] = Math::div($limits[$i], $budgetStr, $scale);
            }
        } else {
            // No limit orders: all budget goes to market
            $marketMargin = $budgetStr;
            $weights[0] = '1';
        }

        // Calculate actual total for verification
        $total = $marketMargin;
        foreach ($limits as $limitMargin) {
            $total = Math::add($total, $limitMargin, $scale);
        }

        return [
            'market' => $marketMargin,
            'limits' => $limits,
            'total' => $total,
            'weights' => $weights,
        ];
    }

    /**
     * Computes notional = margin × leverage (string math).
     *
     * @param  string|int|float  $margin
     * @param  string|int|float  $leverage
     */
    public static function notional($margin, $leverage): string
    {
        return Math::mul($margin, $leverage, Kraite::SCALE);
    }

    /**
     * Computes MARKET leg from the market margin and leverage.
     *
     * v2 semantics:
     *  - market_notional = market_margin × leverage
     *  - basis_price     = $referencePrice when provided; else symbol->mark_price; else symbol->last_price
     *  - market_qty      = market_notional ÷ basis_price
     *
     * @param  string|int|float  $marketMargin  Quote currency margin for the MARKET leg
     * @param  int|string  $leverage  Leverage to apply on the MARKET leg
     * @param  string|int|float|null  $referencePrice  Optional basis price to compute qty from
     * @return array{
     *   price:string,
     *   quantity:string,
     *   amount:string,
     *   margin:string,
     *   notional:string
     * }
     */
    public static function calculateMarketOrderData(
        $marketMargin,
        $leverage,
        ExchangeSymbol $exchangeSymbol,
        $referencePrice = null
    ): array {
        $scale = Kraite::SCALE;

        $margin = (string) $marketMargin;
        if (! is_numeric($margin) || Math::lte($margin, '0', $scale)) {
            throw new InvalidArgumentException("Market margin must be numeric and > 0 (got: {$marketMargin}).");
        }

        if (! is_numeric((string) $leverage) || Math::lt((string) $leverage, '1', 0)) {
            throw new InvalidArgumentException("Leverage must be >= 1 (got: {$leverage}).");
        }
        $L = (int) $leverage;

        // Market notional = margin × L
        $marketNotional = self::notional($margin, $L);

        // Basis price selection (reference > mark > last)
        $basisRaw = null;
        if ($referencePrice !== null && is_numeric((string) $referencePrice) && Math::gt((string) $referencePrice, '0', $scale)) {
            $basisRaw = (string) $referencePrice;
        } elseif (isset($exchangeSymbol->mark_price) && is_numeric((string) $exchangeSymbol->mark_price) && Math::gt((string) $exchangeSymbol->mark_price, '0', $scale)) {
            $basisRaw = (string) $exchangeSymbol->mark_price;
        } elseif (isset($exchangeSymbol->last_price) && is_numeric((string) $exchangeSymbol->last_price) && Math::gt((string) $exchangeSymbol->last_price, '0', $scale)) {
            $basisRaw = (string) $exchangeSymbol->last_price;
        } else {
            throw new RuntimeException('No valid basis price available for market sizing (reference/mark/last are missing or <= 0).');
        }

        // Qty = notional / basis
        $qtyRaw = Math::div($marketNotional, $basisRaw, $scale);
        if ($qtyRaw === null) {
            throw new RuntimeException("Division failed computing market qty (notional={$marketNotional}, basis={$basisRaw}).");
        }

        // Apply symbol precision AFTER numeric sizing
        $qtyFormatted = api_format_quantity($qtyRaw, $exchangeSymbol);
        if (Math::lte($qtyFormatted, '0', $scale)) {
            throw new RuntimeException('Formatted market quantity rounded to zero at current lot size. Increase margin or leverage.');
        }

        $amountFormatted = api_format_price($marketNotional, $exchangeSymbol);
        $priceFormatted = api_format_price($basisRaw, $exchangeSymbol);

        // Margin derived back from notional/L (should equal input margin)
        $marginRaw = Math::div($marketNotional, (string) max(1, $L), $scale);
        $marginFormatted = api_format_price($marginRaw, $exchangeSymbol);

        return [
            'price' => $priceFormatted,
            'quantity' => $qtyFormatted,
            'amount' => $amountFormatted,
            'margin' => $marginFormatted,
            'notional' => $amountFormatted,
        ];
    }

    /**
     * Builds the LIMIT ladder (unbounded).
     * - No budget scaling.
     * - Prices built from referencePrice and side-specific gap% (overrideable via $gapPercent).
     * - Quantities chained from marketOrderQty using step ratios (N-1 provided; last repeats).
     * - Prices clamped to symbol min/max; warnings collected.
     * - Rungs that format to quantity <= 0 are dropped.
     *
     * @param  int|string  $totalLimitOrders  Number of rungs
     * @param  'LONG'|'SHORT'  $direction
     * @param  string|int|float  $referencePrice  Anchor for rung prices
     * @param  string|int|float  $marketOrderQty  Base qty to start chaining from
     * @param  ?array  $limitQuantityMultipliers  Step ratios (N-1 is fine; last repeats)
     * @param  string|int|float|null  $gapPercent  Optional override for gap %, e.g. 8.5 means 8.5%
     * @param  bool  $withMeta  When true, returns ['ladder'=>..., '__meta'=>...]
     * @return array<int,array{price:string,quantity:string,amount:string}>|array{
     *   ladder:array<int,array{price:string,quantity:string,amount:string}>,
     *   __meta:array{activeMultipliers:array<int,string>,warnings:array<int,array<string,mixed>>}
     * }
     */
    public static function calculateLimitOrdersData(
        $totalLimitOrders,
        $direction,
        $referencePrice,
        $marketOrderQty,
        ExchangeSymbol $exchangeSymbol,
        ?array $limitQuantityMultipliers = null,
        $gapPercent = null,
        bool $withMeta = false
    ): array {
        $scale = Kraite::SCALE;

        $direction = mb_strtoupper((string) $direction);
        if (! in_array($direction, ['LONG', 'SHORT'], true)) {
            throw new InvalidArgumentException('Invalid position direction. Must be LONG or SHORT.');
        }

        $N = (int) $totalLimitOrders;
        if ($N < 1) {
            return $withMeta ? ['ladder' => [], '__meta' => ['activeMultipliers' => [], 'warnings' => []]] : [];
        }

        $ref = (string) $referencePrice;
        if (! is_numeric($ref) || Math::lte($ref, '0', $scale)) {
            throw new InvalidArgumentException("referencePrice must be > 0 (got: {$referencePrice}).");
        }

        $marketQ = (string) $marketOrderQty;
        if (! is_numeric($marketQ) || Math::lt($marketQ, '0', $scale)) {
            throw new InvalidArgumentException("marketOrderQty must be >= 0 (got: {$marketOrderQty}).");
        }

        // Gap% resolution: parameter override takes precedence; else fallback by side.
        $effectiveGapPercent = null;
        if ($gapPercent !== null) {
            if (! is_numeric((string) $gapPercent) || Math::lt((string) $gapPercent, '0')) {
                throw new RuntimeException('gapPercent override must be a non-negative number when provided.');
            }
            $effectiveGapPercent = (string) $gapPercent;
        } else {
            $sideGap = $direction === 'LONG'
                ? $exchangeSymbol->percentage_gap_long
                : $exchangeSymbol->percentage_gap_short;

            if (! is_numeric((string) $sideGap) || Math::lt((string) $sideGap, '0')) {
                throw new RuntimeException('percentage_gap_(long|short) must be a non-negative number on the symbol.');
            }
            $effectiveGapPercent = (string) $sideGap;
        }

        // Convert percent → decimal (e.g., 8.5 → 0.085)
        $gapDecimal = Math::div($effectiveGapPercent, '100', $scale);

        // Multipliers precedence: param > symbol default > [2,2,2,2]
        $mArray = $limitQuantityMultipliers
            ?? ($exchangeSymbol->limit_quantity_multipliers ?? [2, 2, 2, 2]);

        if (! is_array($mArray) || empty($mArray)) {
            throw new RuntimeException('limit_quantity_multipliers must be a non-empty array.');
        }

        $warnings = [];
        $usedMultipliers = [];
        $rawPrices = [];
        $fmtPrices = [];
        $rungs = [];

        // Build rung prices first (with clamp to min/max) and keep raw for math.
        for ($i = 1; $i <= $N; $i++) {
            $factor = Math::mul($gapDecimal, (string) $i, $scale);

            $raw = ($direction === 'LONG')
                ? Math::mul($ref, Math::sub('1', $factor, $scale), $scale)
                : Math::mul($ref, Math::add('1', $factor, $scale), $scale);

            if (Math::lte($raw, '0', $scale)) {
                throw new RuntimeException(
                    "Computed limit price <= 0 (rung={$i}, ref={$ref}, gap={$effectiveGapPercent})."
                );
            }

            $clamped = false;
            $origRaw = $raw;

            if (isset($exchangeSymbol->min_price) && is_numeric($exchangeSymbol->min_price)) {
                if (Math::lt($raw, (string) $exchangeSymbol->min_price, $scale)) {
                    $raw = (string) $exchangeSymbol->min_price;
                    $clamped = true;
                }
            }
            if (isset($exchangeSymbol->max_price) && is_numeric($exchangeSymbol->max_price)) {
                if (Math::gt($raw, (string) $exchangeSymbol->max_price, $scale)) {
                    $raw = (string) $exchangeSymbol->max_price;
                    $clamped = true;
                }
            }

            if ($clamped) {
                $warnings[] = [
                    'type' => 'price_clamped',
                    'rung' => $i,
                    'original' => $origRaw,
                    'clamped' => $raw,
                ];
            }

            $rawPrices[] = $raw;
            $fmtPrices[] = api_format_price($raw, $exchangeSymbol);
        }

        // Quantities: chained from market qty using step ratios; last ratio repeats.
        $prev = $marketQ;
        for ($i = 0; $i < $N; $i++) {
            $mi = Kraite::returnLadderedValue($mArray, $i);
            if (! is_numeric($mi) || Math::lte((string) $mi, '0')) {
                throw new RuntimeException('limit_quantity_multipliers must contain positive numeric values');
            }
            $usedMultipliers[] = (string) $mi;

            $prev = Math::mul($prev, (string) $mi, $scale);
            $qtyRaw = $prev;

            // Apply symbol lot/precision formatting
            $qtyFmt = api_format_quantity($qtyRaw, $exchangeSymbol);

            // Drop rung if formatted qty is zero or <= 0
            if (Math::lte($qtyFmt, '0', $scale)) {
                $warnings[] = [
                    'type' => 'rung_dropped_zero_qty',
                    'rung' => $i + 1,
                ];

                continue;
            }

            // Use RAW price × formatted quantity to avoid compounding rounding
            $amountRaw = Math::mul($rawPrices[$i], $qtyFmt, $scale);

            // Reject the entire ladder if any rung would violate Binance/exchange min_notional.
            // Root cause is almost always a corrupt `limit_quantity_multipliers` JSON on the
            // symbol (e.g. [0.2, 0.2, 2, 2] produces shrinking rungs that fall below $5).
            // Failing here propagates up through DispatchLimitOrdersJob → its
            // resolve-exception dispatches CancelPositionJob → position transitions to
            // 'failed'. Better to fail cleanly before placing any limit than to orphan
            // the MARKET entry on the exchange with a partial ladder.
            $minNotional = (string) ($exchangeSymbol->min_notional ?? '0');
            if (Math::gt($minNotional, '0', $scale) && Math::lt($amountRaw, $minNotional, $scale)) {
                throw new RuntimeException(sprintf(
                    'Limit rung #%d on %s would produce notional %s (qty %s × price %s), below min_notional %s. Active multipliers: [%s]. Check limit_quantity_multipliers on the exchange_symbol.',
                    $i + 1,
                    $exchangeSymbol->parsed_trading_pair ?? (string) $exchangeSymbol->token,
                    $amountRaw,
                    $qtyFmt,
                    $rawPrices[$i],
                    $minNotional,
                    implode(', ', $usedMultipliers)
                ));
            }

            $rungs[] = [
                'price' => $fmtPrices[$i],
                'quantity' => $qtyFmt,
                'amount' => api_format_price($amountRaw, $exchangeSymbol),
            ];
        }

        if ($withMeta) {
            return [
                'ladder' => $rungs,
                '__meta' => [
                    'activeMultipliers' => $usedMultipliers,
                    'warnings' => $warnings,
                ],
            ];
        }

        return $rungs;
    }

    /**
     * Calculates PROFIT (TP) order price and quantity from a reference price.
     * - Uses the provided reference price and profit percent.
     * - Clamps to symbol min/max.
     * - Optionally re-anchors to mark price if the computed TP would be on the wrong side of mark.
     *
     * @return array{price:string, quantity:string, amount:string}
     */
    public static function calculateProfitOrder(
        string $direction,
        $referencePrice,
        $profitPercent,
        $currentQty,
        ExchangeSymbol $exchangeSymbol,
        bool $recalculateOnLowerThanMarkPrice = false
    ): array {
        $scale = Kraite::SCALE;

        $direction = mb_strtoupper(mb_trim($direction));
        if (! in_array($direction, ['LONG', 'SHORT'], true)) {
            throw new InvalidArgumentException('Direction must be LONG or SHORT.');
        }

        $ref = (string) $referencePrice;
        if (! is_numeric($ref) || Math::lte($ref, '0', $scale)) {
            throw new InvalidArgumentException("Reference price must be > 0 (got: {$referencePrice}).");
        }

        $qty = (string) $currentQty;
        if (! is_numeric($qty) || Math::lt($qty, '0', $scale)) {
            throw new InvalidArgumentException("Quantity must be >= 0 (got: {$currentQty}).");
        }

        // Percent → decimal (>= 0)
        $pctDecimal = Kraite::pctToDecimal((string) $profitPercent, 'Profit percent');

        // Initial TP calc from ref
        $profitDelta = Math::mul($ref, $pctDecimal, $scale);
        $rawPrice = ($direction === 'LONG')
            ? Math::add($ref, $profitDelta, $scale)
            : Math::sub($ref, $profitDelta, $scale);

        // Optional re-anchor: if TP is on the wrong side of mark, recompute from mark
        if ($recalculateOnLowerThanMarkPrice && isset($exchangeSymbol->mark_price) && is_numeric((string) $exchangeSymbol->mark_price)) {
            $mark = (string) $exchangeSymbol->mark_price;
            $shouldRecalc = ($direction === 'LONG')
                ? Math::lte($rawPrice, $mark, $scale)
                : Math::gte($rawPrice, $mark, $scale);

            if ($shouldRecalc) {
                $ref = $mark;
                $profitDelta = Math::mul($ref, $pctDecimal, $scale);
                $rawPrice = ($direction === 'LONG')
                    ? Math::add($ref, $profitDelta, $scale)
                    : Math::sub($ref, $profitDelta, $scale);
            }
        }

        if (Math::lte($rawPrice, '0', $scale)) {
            throw new RuntimeException("Computed profit price <= 0 (ref={$ref}, pct={$profitPercent}).");
        }

        // Clamp to symbol bounds
        if (isset($exchangeSymbol->min_price) && is_numeric($exchangeSymbol->min_price)) {
            if (Math::lt($rawPrice, (string) $exchangeSymbol->min_price, $scale)) {
                $rawPrice = (string) $exchangeSymbol->min_price;
            }
        }
        if (isset($exchangeSymbol->max_price) && is_numeric($exchangeSymbol->max_price)) {
            if (Math::gt($rawPrice, (string) $exchangeSymbol->max_price, $scale)) {
                $rawPrice = (string) $exchangeSymbol->max_price;
            }
        }

        $price = api_format_price($rawPrice, $exchangeSymbol);
        $quantity = api_format_quantity($qty, $exchangeSymbol);

        // Use RAW price × formatted quantity
        $amountRaw = Math::mul($rawPrice, $quantity, $scale);
        $amount = api_format_price($amountRaw, $exchangeSymbol);

        return [
            'price' => $price,
            'quantity' => $quantity,
            'amount' => $amount,
        ];
    }

    /**
     * Calculates STOP-MARKET trigger price and quantity from an anchor price and stop percent.
     * - LONG: anchor * (1 - pct)
     * - SHORT: anchor * (1 + pct)
     * - Clamps to symbol min/max.
     *
     * @return array{price:string, quantity:string, amount:string}
     */
    public static function calculateStopLossOrder(
        string $direction,
        $anchorPrice,
        $stopPercent,
        $currentQty,
        ExchangeSymbol $exchangeSymbol
    ): array {
        $scale = Kraite::SCALE;

        $direction = mb_strtoupper(mb_trim($direction));
        if (! in_array($direction, ['LONG', 'SHORT'], true)) {
            throw new InvalidArgumentException('Direction must be LONG or SHORT.');
        }

        $anchor = (string) $anchorPrice;
        if (! is_numeric($anchor) || Math::lte($anchor, '0', $scale)) {
            throw new InvalidArgumentException("Anchor price must be > 0 (got: {$anchorPrice}).");
        }

        $qty = (string) $currentQty;
        if (! is_numeric($qty) || Math::lt($qty, '0', $scale)) {
            throw new InvalidArgumentException("Quantity must be >= 0 (got: {$currentQty}).");
        }

        // Percent → decimal (>= 0)
        $pctDecimal = Kraite::pctToDecimal((string) $stopPercent, 'Stop percent');

        // LONG: anchor * (1 - pct)  |  SHORT: anchor * (1 + pct)
        $mult = ($direction === 'SHORT')
            ? Math::add('1', $pctDecimal, $scale)
            : Math::sub('1', $pctDecimal, $scale);

        $rawPrice = Math::mul($anchor, $mult, $scale);

        if (Math::lte($rawPrice, '0', $scale)) {
            throw new RuntimeException("Computed stop price <= 0 (anchor={$anchor}, pct={$stopPercent}).");
        }

        // Clamp to symbol bounds
        if (isset($exchangeSymbol->min_price) && is_numeric($exchangeSymbol->min_price)) {
            if (Math::lt($rawPrice, (string) $exchangeSymbol->min_price, $scale)) {
                $rawPrice = (string) $exchangeSymbol->min_price;
            }
        }
        if (isset($exchangeSymbol->max_price) && is_numeric($exchangeSymbol->max_price)) {
            if (Math::gt($rawPrice, (string) $exchangeSymbol->max_price, $scale)) {
                $rawPrice = (string) $exchangeSymbol->max_price;
            }
        }

        $price = api_format_price($rawPrice, $exchangeSymbol);
        $quantity = api_format_quantity($qty, $exchangeSymbol);

        // Use RAW price × formatted quantity
        $amountRaw = Math::mul($rawPrice, $quantity, $scale);
        $amount = api_format_price($amountRaw, $exchangeSymbol);

        return [
            'price' => $price,
            'quantity' => $quantity,
            'amount' => $amount,
        ];
    }
}
