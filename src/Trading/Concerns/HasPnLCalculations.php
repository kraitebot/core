<?php

declare(strict_types=1);

namespace Kraite\Core\Trading\Concerns;

use InvalidArgumentException;
use Kraite\Core\Trading\Engine;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Support\Math;

trait HasPnLCalculations
{
    /**
     * Accumulative PnL helper.
     * Computes the new weighted-average price after adding a fill, and the unrealized PnL at the fill moment
     * using the fill price as the mark.
     *
     * @return array{
     *   cum_qty:string,
     *   avg_price:string,
     *   pnl:string
     * }
     */
    public static function calculatePnL(
        string $direction,
        $originalQuantity,
        $originalPrice,
        $lastQuantity,
        $lastPrice,
        ?ExchangeSymbol $exchangeSymbol = null
    ): array {
        $scale = Engine::SCALE;
        $dir = mb_strtoupper(mb_trim($direction));

        if (! in_array($dir, ['LONG', 'SHORT'], true)) {
            throw new InvalidArgumentException('Direction must be LONG or SHORT.');
        }

        $Q0 = (string) $originalQuantity;
        $P0 = (string) $originalPrice;
        $Q1 = (string) $lastQuantity;
        $P1 = (string) $lastPrice;

        $cumQty = Math::add($Q0, $Q1, $scale);
        $cumAmount = Math::add(Math::mul($Q0, $P0, $scale), Math::mul($Q1, $P1, $scale), $scale);
        $avg = Math::gt($cumQty, '0', $scale) ? Math::div($cumAmount, $cumQty, $scale) : '0';

        // Mark = fill price at this instant
        $pnlRaw = ($dir === 'LONG')
            ? Math::mul(Math::sub($P1, $avg, $scale), $cumQty, $scale)
            : Math::mul(Math::sub($avg, $P1, $scale), $cumQty, $scale);

        $out = [
            'cum_qty' => $cumQty,
            'avg_price' => $avg,
            'pnl' => $pnlRaw,
        ];

        if ($exchangeSymbol) {
            $out['cum_qty'] = api_format_quantity($cumQty, $exchangeSymbol);
            $out['avg_price'] = api_format_price($avg, $exchangeSymbol);
            $out['pnl'] = api_format_price($pnlRaw, $exchangeSymbol);
        }

        return $out;
    }

    /**
     * Calculates cumulative WAP per row for the provided rows.
     * Note: pass the MARKET row first if you want to include it in the cumulative WAP;
     * otherwise, this will compute over the limits you provide.
     *
     * @param  array<int,array{price:string|int|float,quantity:string|int|float}>  $limits
     * @return array<int,array{rung:int, wap:?string}>
     */
    public static function calculateWAPData(array $limits, string $direction, $profitPercent = null): array
    {
        $scale = Engine::SCALE;

        $direction = mb_strtoupper(mb_trim($direction));
        if (! in_array($direction, ['LONG', 'SHORT'], true)) {
            throw new InvalidArgumentException('Direction must be LONG or SHORT.');
        }

        $useProfit = $profitPercent !== null && $profitPercent !== '';
        $p = '0';
        if ($useProfit) {
            $p = Engine::pctToDecimal((string) $profitPercent, 'profitPercent');
        }

        $out = [];
        $cumQty = '0';
        $cumNotional = '0';

        foreach ($limits as $idx => $row) {
            $price = isset($row['price']) ? (string) $row['price'] : null;
            $qty = isset($row['quantity']) ? (string) $row['quantity'] : null;

            if ($price === null || $qty === null || ! is_numeric($price) || ! is_numeric($qty)) {
                throw new InvalidArgumentException('Each row must have numeric "price" and "quantity".');
            }

            $cumQty = Math::add($cumQty, $qty, $scale);
            $lineNotional = Math::mul($price, $qty, $scale);
            $cumNotional = Math::add($cumNotional, $lineNotional, $scale);

            $wap = null;
            if (Math::gt($cumQty, '0', $scale)) {
                $baseWap = Math::div($cumNotional, $cumQty, $scale);

                if ($useProfit) {
                    $factor = ($direction === 'LONG')
                        ? Math::add('1', $p, $scale)
                        : Math::sub('1', $p, $scale);

                    if (Math::lte($factor, '0', $scale)) {
                        $wap = null;
                    } else {
                        $wap = Math::mul($baseWap, $factor, $scale);
                    }
                } else {
                    $wap = $baseWap;
                }
            }

            $out[] = [
                'rung' => $idx + 1,
                'wap' => $wap,
            ];
        }

        return $out;
    }

    /**
     * Calculate P&L analysis for all position levels.
     *
     * Returns profit if TP hits at each level (MKT, L1, L2, etc.)
     * and total loss if SL triggers after all limits fill.
     *
     * TP is recalculated at each level based on WAP:
     * - LONG: TP = WAP × (1 + tpPercent), Profit = WAP × tpPercent × qty
     * - SHORT: TP = WAP × (1 - tpPercent), Profit = WAP × tpPercent × qty
     *
     * @param  string  $direction  'LONG' or 'SHORT'
     * @param  array{price: string|float, quantity: string|float}  $marketOrder  Market order data
     * @param  array<int, array{price: string|float, quantity: string|float}>  $limitOrders  Limit orders data
     * @param  string|float  $tpPercent  Take profit percentage (e.g., 0.36 for 0.36%)
     * @param  string|float  $slPrice  Stop loss price
     * @return array{levels: array<int, array{level: string, cumulative_qty: string, wap: string, tp_price: string, tp_profit: string}>, sl_loss: string}
     */
    public static function calculatePnLAnalysis(
        string $direction,
        array $marketOrder,
        array $limitOrders,
        $tpPercent,
        $slPrice,
    ): array {
        $scale = Engine::SCALE;
        $dir = mb_strtoupper(mb_trim($direction));

        if (! in_array($dir, ['LONG', 'SHORT'], true)) {
            throw new InvalidArgumentException('Direction must be LONG or SHORT.');
        }

        // Convert TP percent to decimal (e.g., 0.36 -> 0.0036)
        $tpDecimal = Math::div((string) $tpPercent, '100', $scale);
        $slPriceStr = (string) $slPrice;
        $pnlLevels = [];

        // MKT level: only market order filled
        $mktQty = (string) $marketOrder['quantity'];
        $mktPrice = (string) $marketOrder['price'];
        $cumulativeQty = $mktQty;
        $cumulativeCost = Math::mul($mktQty, $mktPrice, $scale);
        $mktWap = $mktPrice;

        // TP recalculated from WAP at this level
        // LONG: TP = WAP × (1 + tpPercent)
        // SHORT: TP = WAP × (1 - tpPercent)
        $mktTpPrice = ($dir === 'LONG')
            ? Math::mul($mktWap, Math::add('1', $tpDecimal, $scale), $scale)
            : Math::mul($mktWap, Math::sub('1', $tpDecimal, $scale), $scale);

        // Profit = WAP × tpPercent × qty (simplified from TP - WAP)
        $mktProfit = Math::mul(Math::mul($mktWap, $tpDecimal, $scale), $cumulativeQty, $scale);

        $pnlLevels[] = [
            'level' => 'MKT',
            'cumulative_qty' => $cumulativeQty,
            'wap' => $mktWap,
            'tp_price' => $mktTpPrice,
            'tp_profit' => $mktProfit,
        ];

        // For each limit level
        foreach ($limitOrders as $i => $row) {
            $limitQty = (string) $row['quantity'];
            $limitPrice = (string) $row['price'];

            $cumulativeQty = Math::add($cumulativeQty, $limitQty, $scale);
            $cumulativeCost = Math::add($cumulativeCost, Math::mul($limitQty, $limitPrice, $scale), $scale);
            $levelWap = Math::div($cumulativeCost, $cumulativeQty, $scale);

            // TP recalculated from WAP at this level
            $levelTpPrice = ($dir === 'LONG')
                ? Math::mul($levelWap, Math::add('1', $tpDecimal, $scale), $scale)
                : Math::mul($levelWap, Math::sub('1', $tpDecimal, $scale), $scale);

            // Profit = WAP × tpPercent × qty
            $levelProfit = Math::mul(Math::mul($levelWap, $tpDecimal, $scale), $cumulativeQty, $scale);

            $pnlLevels[] = [
                'level' => 'L'.($i + 1),
                'cumulative_qty' => $cumulativeQty,
                'wap' => $levelWap,
                'tp_price' => $levelTpPrice,
                'tp_profit' => $levelProfit,
            ];
        }

        // SL loss: assuming all limits filled, then SL hits
        $finalWap = Math::div($cumulativeCost, $cumulativeQty, $scale);
        $slLoss = ($dir === 'LONG')
            ? Math::mul(Math::sub($slPriceStr, $finalWap, $scale), $cumulativeQty, $scale)
            : Math::mul(Math::sub($finalWap, $slPriceStr, $scale), $cumulativeQty, $scale);

        return [
            'levels' => $pnlLevels,
            'sl_loss' => $slLoss,
        ];
    }
}
