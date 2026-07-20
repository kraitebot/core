<?php

declare(strict_types=1);

namespace Kraite\Core\Concerns\Position;

use Kraite\Core\Models\ApiSnapshot;
use Kraite\Core\Support\PositionSnapshot;
use RuntimeException;

trait HasGetters
{
    /**
     * Return non-cancelled/expired LIMIT orders on the same side.
     * Safe: always returns a Collection (possibly empty).
     */
    public function limitOrders()
    {
        return $this->orders()
            ->where('type', 'LIMIT')
            ->whereNotIn('status', ['CANCELLED', 'EXPIRED', 'REJECTED'])
            ->get();
    }

    /**
     * Filled LIMIT and MARKET orders for WAP.
     * Safe: always returns a Collection (possibly empty).
     */
    public function filledOrdersForWAP()
    {
        return $this->orders()
            ->whereIn('type', ['LIMIT', 'MARKET'])
            ->where('status', 'FILLED')
            ->get();
    }

    /**
     * LIMIT and MARKET (non-cancelled/expired) for WAP.
     * Safe: always returns a Collection (possibly empty).
     */
    public function ordersForWAP()
    {
        return $this->orders()
            ->whereIn('type', ['LIMIT', 'MARKET'])
            ->whereNotIn('status', ['CANCELLED', 'EXPIRED'])
            ->get();
    }

    /**
     * LONG <-> SHORT. Throws only if direction is invalid (this indicates data corruption).
     */
    public function oppositeDirection(): string
    {
        return match ($this->direction) {
            'LONG' => 'SHORT',
            'SHORT' => 'LONG',
            default => throw new RuntimeException('oppositeDirection() without a valid direction!'),
        };
    }

    // If total limit orders are filled for this position.
    public function allLimitOrdersFilled(): bool
    {
        return $this->totalLimitOrdersFilled() === $this->total_limit_orders;
    }

    /**
     * Total filled (not partially filled!) LIMIT orders on the same side.
     * Safe: returns integer count (>= 0).
     */
    public function totalLimitOrdersFilled()
    {
        return $this->orders()
            ->where('orders.type', 'LIMIT')
            ->where('orders.status', 'FILLED')
            ->count();
    }

    /**
     * Last LIMIT order (by quantity desc) on the same side that still has a known exchange id and is active/filled.
     * Safe: may return null (callers MUST null-check or use null-safe operator).
     */
    public function lastLimitOrder()
    {
        return $this->orders()
            ->where('orders.type', 'LIMIT')
            ->whereNotNull('orders.exchange_order_id')
            ->whereIn('orders.status', ['NEW', 'PARTIALLY_FILLED', 'FILLED'])
            ->orderByDesc('orders.quantity')
            ->first();
    }

    /**
     * Latest MARKET order on the same side.
     * Safe: may return null.
     */
    public function marketOrder()
    {
        return $this->orders()
            ->where('orders.type', 'MARKET')
            ->orderByDesc('orders.id')
            ->first();
    }

    /**
     * Latest STOP-MARKET order on the same side.
     * Safe: may return null.
     */
    public function stopMarketOrder()
    {
        return $this->orders()
            ->where('orders.type', 'STOP-MARKET')
            ->orderByDesc('orders.id')
            ->first();
    }

    /**
     * Latest MARKET-CANCEL order on the same side.
     * Safe: may return null.
     */
    public function marketCancelOrder()
    {
        return $this->orders()
            ->where('orders.type', 'MARKET-CANCEL')
            ->orderByDesc('orders.id')
            ->first();
    }

    /**
     * Latest live PROFIT order (LIMIT or MARKET) on the same side.
     *
     * Excludes CANCELLED and EXPIRED rows so the correction workflow's
     * cancel-and-recreate window (old row CANCELLED, new row not yet created)
     * never returns a dead order to callers like VerifyIfTPIsFilledJob or
     * CalculateWapAndModifyProfitOrderJob. FILLED is kept intentionally —
     * VerifyIfTPIsFilledJob needs it to detect a just-filled TP.
     *
     * Safe: may return null.
     */
    public function profitOrder()
    {
        return $this->orders()
            ->whereIn('orders.type', ['PROFIT-LIMIT', 'PROFIT-MARKET'])
            ->whereNotIn('orders.status', ['CANCELLED', 'EXPIRED', 'REJECTED'])
            ->orderByDesc('orders.id')
            ->first();
    }

    /**
     * Cost-weighted average price across every FILLED MARKET + LIMIT
     * order on the position. Mirrors Binance's `entryPrice` field on
     * the position endpoint: as additional fills land (DCA / WAP),
     * it stays the running weighted average — not the original
     * opening_price (which is just the first market fill).
     *
     * Returns null when no fills exist yet (position is still
     * queued / opening pre-first-fill).
     */
    public function averageEntryPrice(): ?string
    {
        $totalCost = '0';
        $totalQty = '0';

        foreach ($this->orders()->whereIn('type', ['MARKET', 'LIMIT'])->where('status', 'FILLED')->get() as $order) {
            $price = (string) $order->price;
            $qty = (string) $order->quantity;

            if (! is_numeric($price) || ! is_numeric($qty)) {
                continue;
            }

            $totalCost = bcadd($totalCost, bcmul($price, $qty, scale: 16), scale: 16);
            $totalQty = bcadd($totalQty, $qty, scale: 16);
        }

        if (bccomp($totalQty, '0', scale: 16) <= 0) {
            return null;
        }

        return bcdiv($totalCost, $totalQty, scale: 8);
    }

    /**
     * Unrealised PnL on the position. Computed against:
     *   - the live exchange mark_price (WS-driven, refreshed ~1Hz),
     *   - the cost-weighted average entry across every filled order,
     *   - direction (LONG flips the diff sign vs SHORT).
     *
     * Returns "0" defensively when any datum is missing so callers
     * never have to null-check the formatted string. Output is the
     * api-formatted string (per-symbol price precision).
     */
    public function unrealizedPnl(): ?string
    {
        if ($this->quantity === null || bccomp((string) $this->quantity, '0', scale: 8) === 0) {
            return '0';
        }

        $entryPrice = $this->averageEntryPrice();
        if ($entryPrice === null) {
            return '0';
        }

        $currentPrice = $this->exchangeSymbol?->mark_price;
        if ($currentPrice === null || $currentPrice === '') {
            return '0';
        }

        $currentPrice = (string) $currentPrice;
        $quantity = (string) $this->quantity;

        $diff = $this->direction === 'LONG'
            ? bcsub($currentPrice, $entryPrice, scale: 16)
            : bcsub($entryPrice, $currentPrice, scale: 16);

        $pnl = bcmul($diff, $quantity, scale: 16);

        return (string) api_format_price($pnl, $this->exchangeSymbol);
    }

    /**
     * 0..10 bucket of progress from first profit price to last limit.
     * Safe: returns 0 if anything is missing.
     */
    public function alphaPath(): ?int
    {
        $fraction = $this->alphaPathFraction(8);
        if ($fraction === null) {
            return 0;
        }

        $percent = (float) bcmul($fraction, '100', scale: 2);
        $bucket = (int) ceil($percent / 10.0);

        if ($bucket < 0) {
            $bucket = 0;
        }
        if ($bucket > 10) {
            $bucket = 10;
        }

        return $bucket;
    }

    /**
     * "xx.x" percentage of alpha path (string).
     * Safe: returns "0.0" if anything is missing.
     */
    public function alphaPathPercent(): ?string
    {
        $fraction = $this->alphaPathFraction(6);
        if ($fraction === null) {
            return '0.0';
        }

        return number_format((float) bcmul($fraction, '100', scale: 2), 1, '.', '');
    }

    /**
     * Percentage from entry to next pending LIMIT.
     * Safe: returns 0.0 if anything is missing or degenerate.
     */
    public function percentageToReachLimitOrder(): ?float
    {
        $start = $this->profitOrder()?->price;
        $target = $this->nextPendingLimitOrderPrice();
        $curr = $this->exchangeSymbol?->current_price;

        if ($start === null || $target === null || $curr === null) {
            return 0.0;
        }

        $start = (string) $start;
        $target = (string) $target;
        $curr = (string) $curr;

        if (bccomp($target, $start, scale: 16) > 0) {
            $den = bcsub($target, $start, scale: 16);
            $num = bcsub($curr, $start, scale: 16);
        } else {
            $den = bcsub($start, $target, scale: 16);
            $num = bcsub($start, $curr, scale: 16);
        }

        if (bccomp($den, '0', scale: 16) === 0) {
            return 0.0;
        }

        $fraction = bcdiv($num, $den, scale: 8);

        if (bccomp($fraction, '0', scale: 8) < 0) {
            $fraction = '0';
        } elseif (bccomp($fraction, '1', scale: 8) > 0) {
            $fraction = '1';
        }

        return (float) bcmul($fraction, '100', scale: 2);
    }

    /**
     * Closeness % of current price between entry and next LIMIT.
     * Safe: returns 0.0 if anything is missing or degenerate.
     */
    public function closenessToNextLimitPercent(): ?float
    {
        $start = $this->profitOrder()?->price;
        $end = $this->nextPendingLimitOrderPrice();
        $curr = $this->exchangeSymbol?->current_price;

        if ($start === null || $end === null || $curr === null) {
            return 0.0;
        }

        $start = (string) $start;
        $end = (string) $end;
        $curr = (string) $curr;

        if (bccomp($end, $start, scale: 16) > 0) {
            if (bccomp($curr, $start, scale: 16) <= 0) {
                $fraction = '0';
            } elseif (bccomp($curr, $end, scale: 16) >= 0) {
                $fraction = '1';
            } else {
                $num = bcsub($curr, $start, scale: 16);
                $den = bcsub($end, $start, scale: 16);
                if (bccomp($den, '0', scale: 16) === 0) {
                    return 0.0;
                }
                $fraction = bcdiv($num, $den, scale: 8);
            }
        } else {
            if (bccomp($curr, $start, scale: 16) >= 0) {
                $fraction = '0';
            } elseif (bccomp($curr, $end, scale: 16) <= 0) {
                $fraction = '1';
            } else {
                $num = bcsub($start, $curr, scale: 16);
                $den = bcsub($start, $end, scale: 16);
                if (bccomp($den, '0', scale: 16) === 0) {
                    return 0.0;
                }
                $fraction = bcdiv($num, $den, scale: 8);
            }
        }

        return (float) bcmul($fraction, '100', scale: 2);
    }

    /**
     * Price of the next pending LIMIT rung — the smallest-qty unfilled rung,
     * matching the martingale ladder convention (rung 1 = smallest qty,
     * closest to entry). Quantity ordering is robust to insertion-order
     * variance — recovered positions create Order rows in non-rung sequence
     * with MARKET inserted last, so an id-anchored filter would exclude
     * every pending rung. Safe: may return null; callers must guard.
     */
    public function nextPendingLimitOrderPrice(): ?string
    {
        $next = $this->orders()
            ->where('type', 'LIMIT')
            ->whereNotIn('status', ['FILLED', 'CANCELLED', 'EXPIRED'])
            ->orderBy('quantity')
            ->first();

        return $next?->price ? (string) $next->price : null;
    }

    /**
     * Notional size (qty * mark). Returns a rounded string.
     * Safe: returns "0" if any datum is missing.
     */
    public function size(): ?string
    {
        if ($this->quantity === null || $this->exchangeSymbol?->current_price === null) {
            return '0';
        }

        $quantity = (string) $this->quantity;
        $currentPrice = (string) $this->exchangeSymbol->current_price;

        $notional = bcmul($quantity, $currentPrice, scale: 16);

        $openPositions = ApiSnapshot::getFrom($this->account, 'account-positions');

        if (is_array($openPositions)) {
            $position = PositionSnapshot::fromValidatedResult($openPositions)->matchingPosition($this);

            if (is_array($position) && array_key_exists('notional', $position)) {
                return $position['notional'];
            }
        }

        return 0;
    }

    /**
     * Core fraction [0..1] of (current - start) / (end - start) respecting direction.
     * Safe: returns "0" if any of start/end/current is missing or degenerate.
     */
    private function alphaPathFraction(int $scale): ?string
    {
        // Start is the first profit price on this position
        $start = $this->first_profit_price;

        // End is the last LIMIT order price
        $lastLimit = $this->lastLimitOrder();              // may be null (SAFE)
        $end = $lastLimit?->price;                   // null-safe

        // Current price from latest candle
        $curr = $this->exchangeSymbol?->current_price;

        // If any required piece is missing, return "0"
        if ($start === null || $end === null || $curr === null) {
            return '0';
        }

        $start = (string) $start;
        $end = (string) $end;
        $curr = (string) $curr;

        // Compute fraction depending on whether end < start or end >= start
        if (bccomp($end, $start, scale: 16) < 0) {
            if (bccomp($curr, $start, scale: 16) >= 0) {
                $fraction = '0';
            } elseif (bccomp($curr, $end, scale: 16) <= 0) {
                $fraction = '1';
            } else {
                $num = bcsub($start, $curr, scale: 16);
                $den = bcsub($start, $end, scale: 16);
                if (bccomp($den, '0', scale: 16) === 0) {
                    return '0';
                }
                $fraction = bcdiv($num, $den, scale: $scale);
            }
        } else {
            if (bccomp($curr, $start, scale: 16) <= 0) {
                $fraction = '0';
            } elseif (bccomp($curr, $end, scale: 16) >= 0) {
                $fraction = '1';
            } else {
                $num = bcsub($curr, $start, scale: 16);
                $den = bcsub($end, $start, scale: 16);
                if (bccomp($den, '0', scale: 16) === 0) {
                    return '0';
                }
                $fraction = bcdiv($num, $den, scale: $scale);
            }
        }

        // Clamp to [0,1]
        if (bccomp($fraction, '0', scale: $scale) < 0) {
            $fraction = '0';
        } elseif (bccomp($fraction, '1', scale: $scale) > 0) {
            $fraction = '1';
        }

        return $fraction;
    }

    /**
     * Last filled LIMIT/MARKET price on this side; falls back to opening_price.
     * Safe: may return null (use only where optional).
     */
    private function lastFilledLimitOrMarketPrice(): ?string
    {
        $base = $this->orders()
            ->where('status', 'FILLED');

        $lastFilledLimit = (clone $base)
            ->where('type', 'LIMIT')
            ->orderByDesc('id')
            ->first();

        if ($lastFilledLimit) {
            return (string) ($lastFilledLimit->avg_price ?? $lastFilledLimit->price);
        }

        $lastFilledMarket = (clone $base)
            ->where('type', 'MARKET')
            ->orderByDesc('id')
            ->first();

        if ($lastFilledMarket) {
            return (string) ($lastFilledMarket->avg_price ?? $lastFilledMarket->price);
        }

        return $this->opening_price !== null ? (string) $this->opening_price : null;
    }
}
