<?php

declare(strict_types=1);

namespace Kraite\Core\Trading\Concerns;

use InvalidArgumentException;
use Kraite\Core\Trading\Kraite;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\LeverageBracket;
use Kraite\Core\Support\Math;
use RuntimeException;

trait HasPositionPlanning
{
    /**
     * Plans an unbounded position:
     * - Computes feasible leverage (<= $leverageCap) via unit-leverage worst-case K with configurable headroom.
     * - Computes the MARKET leg (using the chosen leverage).
     * - Builds the unbounded LIMIT ladder (drop-zero, clamp prices with warnings).
     *
     * Returns a structured payload suitable for the simulator.
     *
     * @param  'LONG'|'SHORT'  $direction
     * @param  string|int|float  $referencePrice
     * @param  string|int|float  $marketMargin
     * @return array{
     *   leverage: array{requestedCap:int, chosen:int, reason:string, bracket:array<string,mixed>|null},
     *   market_order: array{price:string,quantity:string,amount:string,margin:string},
     *   limit_ladder: array<int,array{price:string,quantity:string,amount:string}>,
     *   totals: array{market_notional:string,limits_notional:string,total_notional:string,required_margin:string,blow_up_factor:string},
     *   diagnostics?: array{K_unit:string, headroom_pct:string, intervals_considered:array<int,array<string,mixed>>, active_ratios:array<int,string>, warnings:array<int,array<string,mixed>>}
     * }
     */
    public static function planUnboundedPosition(
        ExchangeSymbol $exchangeSymbol,
        string $direction,
        $referencePrice,
        $marketMargin,
        int $leverageCap,
        int $totalLimitOrders,
        ?array $limitQuantityMultipliers = null,
        bool $withDiagnostics = true
    ): array {
        $scale = Kraite::SCALE;

        $direction = mb_strtoupper(mb_trim($direction));
        if (! in_array($direction, ['LONG', 'SHORT'], true)) {
            throw new InvalidArgumentException('Direction must be LONG or SHORT.');
        }

        $ref = (string) $referencePrice;
        if (! is_numeric($ref) || Math::lte($ref, '0', $scale)) {
            throw new InvalidArgumentException("referencePrice must be > 0 (got: {$referencePrice}).");
        }

        $M0 = (string) $marketMargin;
        if (! is_numeric($M0) || Math::lte($M0, '0', $scale)) {
            throw new InvalidArgumentException("marketMargin must be > 0 (got: {$marketMargin}).");
        }

        if ($leverageCap < 1) {
            throw new InvalidArgumentException('leverageCap must be >= 1.');
        }

        $N = (int) $totalLimitOrders;
        if ($N < 1) {
            throw new InvalidArgumentException('totalLimitOrders must be >= 1.');
        }

        // Step ratios precedence
        $ratios = $limitQuantityMultipliers
            ?? ($exchangeSymbol->limit_quantity_multipliers ?? [2, 2, 2, 2]);

        if (! is_array($ratios) || empty($ratios)) {
            throw new RuntimeException('limit_quantity_multipliers must be a non-empty array.');
        }

        /* -------------------------------
         * 1) Unit-leverage worst-case K
         * ------------------------------- */
        // Unit-L market qty: Q0' = M0 / P0
        $Q0_unit = Math::div($M0, $ref, $scale);

        // Prices for K computation (with the same clamp/warnings policy)
        $gapPercent = $direction === 'LONG'
            ? $exchangeSymbol->percentage_gap_long
            : $exchangeSymbol->percentage_gap_short;

        $gapDecimal = Math::div((string) $gapPercent, '100', $scale);

        $pricesK = [];
        $warningsK = [];
        for ($i = 1; $i <= $N; $i++) {
            $factor = Math::mul($gapDecimal, (string) $i, $scale);
            $raw = ($direction === 'LONG')
                ? Math::mul($ref, Math::sub('1', $factor, $scale), $scale)
                : Math::mul($ref, Math::add('1', $factor, $scale), $scale);

            $clamped = false;
            $orig = $raw;

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
                $warningsK[] = [
                    'type' => 'price_clamped',
                    'rung' => $i,
                    'original' => $orig,
                    'clamped' => $raw,
                ];
            }

            $pricesK[] = $raw;
        }

        // Quantities (unit-L) chained from Q0'
        $rawQtysUnit = [];
        $prev = $Q0_unit;
        $activeRatios = [];
        for ($i = 0; $i < $N; $i++) {
            $mi = Kraite::returnLadderedValue($ratios, $i);
            if (! is_numeric($mi) || (float) $mi <= 0) {
                throw new RuntimeException('limit_quantity_multipliers must contain positive numeric values');
            }
            $activeRatios[] = (string) $mi;
            $prev = Math::mul($prev, (string) $mi, $scale);
            $rawQtysUnit[$i] = $prev;
        }

        // Limits notional at L=1 (raw, conservative)
        $A_lim_unit = '0';
        for ($i = 0; $i < $N; $i++) {
            $A_lim_unit = Math::add($A_lim_unit, Math::mul($pricesK[$i], $rawQtysUnit[$i], $scale), $scale);
        }

        // K_raw = M0 + A_lim_unit ; apply headroom
        $K_raw = Math::add($M0, $A_lim_unit, $scale);
        $h = (string) (config('kraite.bracket_headroom_pct', Kraite::BRACKET_HEADROOM_PCT));
        $K = Math::mul($K_raw, Math::add('1', $h, $scale), $scale);

        // Determine feasible leverage via bracket intervals
        $intervals = [];
        $brackets = LeverageBracket::query()
            ->where('exchange_symbol_id', (int) $exchangeSymbol->id)
            ->orderBy('notional_floor')
            ->get([
                'bracket', 'initial_leverage', 'notional_floor', 'notional_cap', 'maint_margin_ratio',
            ]);

        $bestL = 0;
        $bestBkt = null;
        foreach ($brackets as $b) {
            $floor = (string) $b->notional_floor;
            $cap = (string) $b->notional_cap;
            $initL = (int) $b->initial_leverage;

            // Lmin = ceil(floor / K), LmaxFromCap = floor(cap / K)
            $Lmin = Kraite::ceilPosDiv($floor, $K);
            $LmaxFromCap = Kraite::floorPosDiv($cap, $K);
            $Lmax = min($LmaxFromCap, $initL, $leverageCap);

            $intervals[] = [
                'bracket' => $b->toArray(),
                'Lmin' => $Lmin,
                'Lmax' => $Lmax,
            ];

            if ($Lmin <= $Lmax && $Lmax > $bestL) {
                $bestL = $Lmax;
                $bestBkt = $b;
            }
        }

        if ($bestL < 1) {
            $chosenLev = 1;
            $levInfo = [
                'requestedCap' => (int) $leverageCap,
                'chosen' => $chosenLev,
                'reason' => 'no_feasible',
                'bracket' => null,
            ];
        } else {
            $chosenLev = (int) $bestL;
            $levInfo = [
                'requestedCap' => (int) $leverageCap,
                'chosen' => $chosenLev,
                'reason' => ($chosenLev === $leverageCap ? 'target_ok_or_top_cap' : 'clamped_by_bracket'),
                'bracket' => $bestBkt ? $bestBkt->toArray() : null,
            ];
        }

        /* -----------------------------
         * 2) MARKET leg at chosen L
         * ----------------------------- */
        $market = Kraite::calculateMarketOrderData($M0, $chosenLev, $exchangeSymbol, $referencePrice);

        /* -----------------------------
         * 3) Unbounded ladder (no cap)
         * ----------------------------- */
        $ladderPayload = Kraite::calculateLimitOrdersData(
            $N,
            $direction,
            $ref,
            (string) $market['quantity'],
            $exchangeSymbol,
            $ratios,
            null,
            true
        );

        $ladder = $ladderPayload['ladder'] ?? [];
        $warns = $ladderPayload['__meta']['warnings'] ?? [];
        $active = $ladderPayload['__meta']['activeMultipliers'] ?? [];

        // Compute limits-only notional from formatted rungs
        $A_lim = '0';
        foreach ($ladder as $row) {
            $A_lim = Math::add($A_lim, Math::mul((string) $row['price'], (string) $row['quantity'], $scale), $scale);
        }

        // Totals at chosen leverage
        $A_mkt = Math::mul($M0, (string) $chosenLev, $scale);
        $A_tot = Math::add($A_mkt, $A_lim, $scale);
        $reqMargin = Math::div($A_tot, (string) max(1, $chosenLev), $scale);
        $blowUp = Math::equal($A_mkt, '0', $scale) ? '0' : Math::div($A_tot, $A_mkt, $scale);

        $out = [
            'leverage' => $levInfo,
            'market_order' => [
                'price' => $market['price'],
                'quantity' => $market['quantity'],
                'amount' => $market['amount'],
                'margin' => $market['margin'],
            ],
            'limit_ladder' => $ladder,
            'totals' => [
                'market_notional' => api_format_price($A_mkt, $exchangeSymbol),
                'limits_notional' => api_format_price($A_lim, $exchangeSymbol),
                'total_notional' => api_format_price($A_tot, $exchangeSymbol),
                'required_margin' => api_format_price($reqMargin, $exchangeSymbol),
                'blow_up_factor' => $blowUp,
            ],
        ];

        if ($withDiagnostics) {
            $out['diagnostics'] = [
                'K_unit' => $K ?? '0',
                'headroom_pct' => (string) $h,
                'intervals_considered' => $intervals ?? [],
                'active_ratios' => $active,
                'warnings' => array_merge($warningsK, $warns),
            ];
        }

        return $out;
    }
}
