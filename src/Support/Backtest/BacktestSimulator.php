<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Backtest;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Kraite\Core\Models\Candle;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Support\Math;
use Kraite\Core\Trading\Kraite;
use Throwable;

/**
 * BacktestSimulator
 *
 * Ported from the legacy martingalian `AnalyseNonReboundablesCommand`.
 * Purpose: worst-case stress-test for the martingale ladder on a single
 * ExchangeSymbol across historical candles.
 *
 * For every candle in `candles` for (symbol, timeframe):
 *   1. Assume a WORST-CASE entry — candle high for LONG, candle low
 *      for SHORT. This simulates the most adverse fill the live bot
 *      could suffer at that moment.
 *   2. Build the same market + limit ladder the production trader
 *      would place, using Kraite::calculateMarketOrderData and
 *      Kraite::calculateLimitOrdersData (respecting the caller's
 *      config overrides for TP / gap / SL / multipliers / N).
 *   3. Walk forward candle by candle. Promote `maxFilledRung` when a
 *      later candle's wick crosses a limit price (simulated fill,
 *      re-averages WAP). Check TP hit against the new WAP-based
 *      target after every promotion.
 *   4. After last rung is touched (unless skipStopLoss), check SL
 *      anchored at rung N with stopPercent distance.
 *   5. Classify outcome per (candle × direction):
 *        - tp_hit_from_market_only   — closed without any limit fill
 *        - reboundable               — ladder averaged, TP hit from WAP
 *        - stopped_out               — last rung + SL triggered
 *        - non-reboundable           — data exhausted, never recovered
 *
 * The service is a pure consumer of the `candles` table. It does not
 * fetch, store, or mutate market data. Candle population is the job
 * of the Binance Vision / TAAPI fetchers called elsewhere.
 *
 * Leverage does NOT influence rebound outcomes — rebounds are a price
 * property, not a capital property. Leverage here is accepted purely
 * so the Kraite market-order sizing works (notional = margin × leverage);
 * the actual "did price come back" logic is leverage-agnostic.
 *
 * The three knobs that DO move rebound rates:
 *   • tpPercent               — how far price must retrace to close
 *   • gapLongPercent /
 *     gapShortPercent         — where each rung sits relative to entry
 *   • slPercent               — distance of SL from the last rung
 */
final class BacktestSimulator
{
    private const SCALE = 16;

    /**
     * Simulate the ladder against historical candles for one symbol.
     *
     * @param  ExchangeSymbol  $symbol  Symbol to test (candles looked up by symbol + timeframe).
     * @param  string  $timeframe  Candle timeframe: 1h / 4h / 12h.
     * @param  string  $margin  Quote margin per virtual position.
     * @param  int  $leverage  Used for market sizing only.
     * @param  int  $totalLimitOrders  Ladder depth N.
     * @param  array<int,string>|null  $multipliers  Optional per-rung qty multipliers override.
     * @param  string  $tpPercent  Profit percent (e.g. '0.36' for 0.36%).
     * @param  string|null  $gapLongPercent  Gap % for LONG ladder (overrides symbol value).
     * @param  string|null  $gapShortPercent  Gap % for SHORT ladder (overrides symbol value).
     * @param  string  $slPercent  Stop-loss percent off rung N.
     * @param  bool  $skipStopLoss  If true, SL evaluation is skipped entirely.
     * @param  int  $daysToIgnore  Suppress displaying rows whose start candle falls within the last N days (analysis still uses all data).
     * @param  int|null  $limitHit  Only return rows whose deepest touched rung >= this.
     * @param  bool  $nonReboundableOnly  Filter output to non-reboundable + stopped_out statuses.
     * @param  Carbon|null  $specificCandle  Run the sim against this single candle only.
     * @return array{
     *   rows: array<int, array<string, mixed>>,
     *   totals: array{candles: int, stops: int, non_reboundable: int, tp_market_only: int, reboundable: int},
     *   meta: array<string, mixed>
     * }
     */
    public function simulate(
        ExchangeSymbol $symbol,
        string $timeframe,
        string $margin,
        int $leverage,
        int $totalLimitOrders,
        ?array $multipliers,
        string $tpPercent,
        ?string $gapLongPercent,
        ?string $gapShortPercent,
        string $slPercent,
        bool $skipStopLoss = false,
        int $daysToIgnore = 20,
        ?int $limitHit = null,
        bool $nonReboundableOnly = false,
        ?Carbon $specificCandle = null,
    ): array {
        $this->validate($timeframe, $margin, $leverage, $totalLimitOrders, $daysToIgnore);

        $tz = config('app.timezone', 'UTC');
        $displayCutoff = Carbon::now($tz)->subDays($daysToIgnore);

        $all = Candle::query()
            ->where('exchange_symbol_id', $symbol->id)
            ->where('timeframe', $timeframe)
            ->orderBy('candle_time_utc')
            ->get(['id', 'open', 'high', 'low', 'close', 'candle_time_utc']);

        if ($all->isEmpty()) {
            return $this->emptyResult('No candles present for this symbol / timeframe.');
        }

        // Preserve original positional index into $all so single-candle
        // mode still walks forward from the right starting point.
        $startIndices = $specificCandle !== null
            ? $this->findSingleCandleIndex($all, $specificCandle)
            : range(0, $all->count() - 1);

        if (empty($startIndices)) {
            return $this->emptyResult('No start candles matched the requested selector.');
        }

        $rows = [];
        $totals = [
            'candles' => 0,
            'stops' => 0,
            'non_reboundable' => 0,
            'tp_market_only' => 0,
            'reboundable' => 0,
        ];

        foreach ($startIndices as $startIdx) {
            $totals['candles']++;
            /** @var Candle $startCandle */
            $startCandle = $all[$startIdx];

            $suppress = $specificCandle === null
                && Carbon::parse((string) $startCandle->candle_time_utc)->gte($displayCutoff);

            foreach (['LONG', 'SHORT'] as $direction) {
                $result = $this->simulateOne(
                    direction: $direction,
                    startIdx: $startIdx,
                    allCandles: $all,
                    symbol: $symbol,
                    margin: $margin,
                    leverage: $leverage,
                    totalLimitOrders: $totalLimitOrders,
                    multipliers: $multipliers,
                    tpPercent: $tpPercent,
                    gapPercent: $direction === 'LONG' ? $gapLongPercent : $gapShortPercent,
                    slPercent: $slPercent,
                    skipStopLoss: $skipStopLoss,
                );

                if ($result === null) {
                    continue;
                }

                $status = $result['status'];
                match ($status) {
                    'stopped_out' => $totals['stops']++,
                    'non-reboundable' => $totals['non_reboundable']++,
                    'tp_hit_from_market_only' => $totals['tp_market_only']++,
                    'reboundable' => $totals['reboundable']++,
                    default => null,
                };

                if (! $suppress && $this->shouldIncludeRow($result, $limitHit, $nonReboundableOnly)) {
                    $rows[] = $result;
                }
            }
        }

        return [
            'rows' => $rows,
            'totals' => $totals,
            'meta' => [
                'symbol' => $symbol->parsed_trading_pair,
                'timeframe' => $timeframe,
                'total_candles_in_db' => $all->count(),
                'display_cutoff' => $displayCutoff->toDateTimeString(),
                'tp_percent' => $tpPercent,
                'sl_percent' => $slPercent,
                'gap_long_percent' => $gapLongPercent ?? (string) $symbol->percentage_gap_long,
                'gap_short_percent' => $gapShortPercent ?? (string) $symbol->percentage_gap_short,
                'margin' => $margin,
                'leverage' => $leverage,
                'total_limit_orders' => $totalLimitOrders,
                'multipliers' => $multipliers ?? ($symbol->limit_quantity_multipliers ?? [2, 2, 2, 2]),
                'skip_stop_loss' => $skipStopLoss,
            ],
        ];
    }

    /**
     * Run a single (direction × start candle) simulation.
     *
     * Mirrors the core walk-forward loop of AnalyseNonReboundablesCommand::simulate
     * but built on Kraite's calculators. Market qty is sized with the
     * production divider applied (margin × leverage / 2^(N+1)) so the
     * walk-forward sees the exact same ladder shape the live trader
     * would place — critical for correct min_notional gating on
     * low-price tokens.
     *
     * @return array<string, mixed>|null
     */
    private function simulateOne(
        string $direction,
        int $startIdx,
        Collection $allCandles,
        ExchangeSymbol $symbol,
        string $margin,
        int $leverage,
        int $totalLimitOrders,
        ?array $multipliers,
        string $tpPercent,
        ?string $gapPercent,
        string $slPercent,
        bool $skipStopLoss,
    ): ?array {
        /** @var Candle $start */
        $start = $allCandles[$startIdx];

        $entryRef = $direction === 'LONG'
            ? (string) $start->high
            : (string) $start->low;

        // Market leg — sized to match what production actually places.
        // Production callers (VerifyOrderNotionalForMarketOrderJob and
        // PlaceMarketOrderJob) pre-divide (margin × leverage) by
        // 2^(N+1) so the market opens at 1/32 of the budget for N=4;
        // the limit ladder then chains on top up to ~97% at full fill.
        // Kraite::calculateMarketOrderData itself does NOT apply the
        // divider (it's the "unbounded ladder" primitive) — the
        // callers do. For the simulator to mirror live-trading
        // behaviour — especially the min_notional gating that fires on
        // low-price tokens — we pre-divide here so the market qty fed
        // into calculateLimitOrdersData matches the real on-book order.
        $divider = get_market_order_amount_divider($totalLimitOrders);
        $dividedMargin = Math::div($margin, (string) $divider, self::SCALE);

        try {
            $market = Kraite::calculateMarketOrderData($dividedMargin, $leverage, $symbol, $entryRef);
        } catch (Throwable $e) {
            return [
                'direction' => $direction,
                'start_candle' => $this->ctString($start->candle_time_utc),
                'entry_ref_price' => $entryRef,
                'last_rung' => 0,
                'last_touch_candle' => null,
                'tp_price' => 'N/A',
                'tp_hit_candle' => null,
                'candles_to_profit' => null,
                'status' => 'skipped',
                'message' => 'Market sizing failed: '.$e->getMessage(),
            ];
        }

        $marketQty = (string) $market['quantity'];

        // Limit ladder — placed at ±gap% from entry, quantities chained from market qty.
        try {
            $ladder = Kraite::calculateLimitOrdersData(
                totalLimitOrders: $totalLimitOrders,
                direction: $direction,
                referencePrice: $entryRef,
                marketOrderQty: $marketQty,
                exchangeSymbol: $symbol,
                limitQuantityMultipliers: $multipliers,
                gapPercent: $gapPercent,
            );
        } catch (Throwable $e) {
            return [
                'direction' => $direction,
                'start_candle' => $this->ctString($start->candle_time_utc),
                'entry_ref_price' => $entryRef,
                'last_rung' => 0,
                'last_touch_candle' => null,
                'tp_price' => 'N/A',
                'tp_hit_candle' => null,
                'candles_to_profit' => null,
                'status' => 'skipped',
                'message' => 'Ladder build failed: '.$e->getMessage(),
            ];
        }

        if (count($ladder) < 1) {
            return [
                'direction' => $direction,
                'start_candle' => $this->ctString($start->candle_time_utc),
                'entry_ref_price' => $entryRef,
                'last_rung' => 0,
                'last_touch_candle' => null,
                'tp_price' => 'N/A',
                'tp_hit_candle' => null,
                'candles_to_profit' => null,
                'status' => 'skipped',
                'message' => 'Ladder empty after min-notional / min-qty gating.',
            ];
        }

        // TP price by rung (rung 0 = market only; rung k = cumulative fills 1..k).
        $tpByRung = $this->computeTpPerRungLimitOnly($direction, $tpPercent, $entryRef, $ladder, $marketQty);

        $priceByRung = [];
        foreach ($ladder as $i => $row) {
            $priceByRung[$i + 1] = (string) $row['price'];
        }

        // Walk-forward state
        $maxFilledRung = 0;
        $lastTouchIdx = $startIdx;
        $currentTpPrice = $tpByRung[0] ?? null;

        $closestDiff = null;
        $closestPrice = null;
        $closestCandle = null;

        // SL price is invariant once the last rung is touched — compute
        // at the promotion boundary, cache for the rest of the walk.
        // `false` = not yet computed; `null` = computed but ladder math
        // rejected it (skip SL checks entirely from here on).
        $cachedSlPrice = false;

        for ($i = $startIdx + 1; $i < $allCandles->count(); $i++) {
            /** @var Candle $c */
            $c = $allCandles[$i];

            // Promote to deepest limit rung touched on this candle.
            $promotedTo = $this->deepestTouchedRung($direction, $c, $priceByRung, $maxFilledRung);
            if ($promotedTo > $maxFilledRung) {
                $maxFilledRung = $promotedTo;
                $lastTouchIdx = $i;
                $currentTpPrice = $tpByRung[$maxFilledRung] ?? null;

                $closestDiff = null;
                $closestPrice = null;
                $closestCandle = null;

                if (! $skipStopLoss && $maxFilledRung === $totalLimitOrders && $cachedSlPrice === false) {
                    $cachedSlPrice = $this->resolveStopLossPrice(
                        $direction,
                        $priceByRung[$totalLimitOrders],
                        $slPercent,
                        $this->cumulativeQtyAtRung($marketQty, $ladder, $totalLimitOrders),
                        $symbol,
                    );
                }

                continue; // never same-bar TP after a touch
            }

            // TP check after touch bar.
            if ($i > $lastTouchIdx && $currentTpPrice !== null) {
                if ($this->tpHit($direction, $c, $currentTpPrice)) {
                    return [
                        'direction' => $direction,
                        'start_candle' => $this->ctString($start->candle_time_utc),
                        'entry_ref_price' => $entryRef,
                        'last_rung' => $maxFilledRung,
                        'last_touch_candle' => $this->ctString($allCandles[$lastTouchIdx]->candle_time_utc),
                        'tp_price' => $currentTpPrice,
                        'tp_hit_candle' => $this->ctString($c->candle_time_utc),
                        'candles_to_profit' => $i - $lastTouchIdx,
                        'status' => $maxFilledRung === 0 ? 'tp_hit_from_market_only' : 'reboundable',
                        'message' => '',
                    ];
                }

                // Track closest approach when TP NOT hit — useful to see how
                // close a non-rebound case got before the walker exhausted.
                $this->trackClosestApproach(
                    $direction,
                    $c,
                    $currentTpPrice,
                    $closestDiff,
                    $closestPrice,
                    $closestCandle
                );
            }

            // SL only after last rung touched. $cachedSlPrice===null means
            // the SL couldn't be computed (clamped to zero etc.) — skip so
            // we don't false-positive the stopped_out tally.
            if ($cachedSlPrice !== false && $cachedSlPrice !== null && $i > $lastTouchIdx) {
                if ($this->slHit($direction, $c, $cachedSlPrice)) {
                    return [
                        'direction' => $direction,
                        'start_candle' => $this->ctString($start->candle_time_utc),
                        'entry_ref_price' => $entryRef,
                        'last_rung' => $maxFilledRung,
                        'last_touch_candle' => $this->ctString($allCandles[$lastTouchIdx]->candle_time_utc),
                        'tp_price' => $currentTpPrice,
                        'tp_hit_candle' => null,
                        'candles_to_profit' => null,
                        'status' => 'stopped_out',
                        'message' => sprintf(
                            'Stopped out at %s on %s after last rung (N=%d) touched at %s.',
                            $cachedSlPrice,
                            $this->ctString($c->candle_time_utc),
                            $totalLimitOrders,
                            $this->ctString($allCandles[$lastTouchIdx]->candle_time_utc)
                        ),
                    ];
                }
            }
        }

        // End of data — never recovered.
        $note = 'Non-reboundable: no subsequent candle reached TP of '.($currentTpPrice ?? 'N/A');
        if ($closestPrice !== null && $closestCandle !== null) {
            $note .= sprintf(' (closest %s on %s)', $closestPrice, $this->ctString($closestCandle));
        }

        return [
            'direction' => $direction,
            'start_candle' => $this->ctString($start->candle_time_utc),
            'entry_ref_price' => $entryRef,
            'last_rung' => $maxFilledRung,
            'last_touch_candle' => $this->ctString($allCandles[$lastTouchIdx]->candle_time_utc),
            'tp_price' => $currentTpPrice ?? 'N/A',
            'tp_hit_candle' => null,
            'candles_to_profit' => null,
            'status' => 'non-reboundable',
            'message' => $note,
        ];
    }

    /**
     * Per-rung TP prices. Rung 0 is market-only; rung k (k>=1) re-averages
     * WAP over the first k limit fills (market excluded by design — mirrors
     * the live trader's post-fill TP recompute logic).
     *
     * @param  array<int, array{price: string, quantity: string, amount: string}>  $ladder
     * @return array<int, string|null>
     */
    private function computeTpPerRungLimitOnly(
        string $direction,
        string $tpPercent,
        string $entryRef,
        array $ladder,
        string $marketQty,
    ): array {
        $tpByRung = [];

        $marketSeries = Kraite::calculateWAPData(
            [['price' => $entryRef, 'quantity' => $marketQty]],
            $direction,
            $tpPercent
        );
        $tpByRung[0] = $marketSeries[0]['wap'] ?? null;

        $accum = [];
        foreach ($ladder as $idx => $rung) {
            $accum[] = [
                'price' => (string) $rung['price'],
                'quantity' => (string) $rung['quantity'],
            ];
            $series = Kraite::calculateWAPData($accum, $direction, $tpPercent);
            $tpByRung[$idx + 1] = $series[$idx]['wap'] ?? null;
        }

        return $tpByRung;
    }

    /**
     * Deepest limit rung touched on this candle (LONG: low ≤ rung price,
     * SHORT: high ≥ rung price).
     *
     * @param  array<int, string>  $priceByRung
     */
    private function deepestTouchedRung(string $direction, Candle $c, array $priceByRung, int $currentRung): int
    {
        $deepest = $currentRung;

        if ($direction === 'LONG') {
            foreach ($priceByRung as $rung => $price) {
                if ($rung > $deepest && Math::lte((string) $c->low, $price, self::SCALE)) {
                    $deepest = $rung;
                }
            }

            return $deepest;
        }

        foreach ($priceByRung as $rung => $price) {
            if ($rung > $deepest && Math::gte((string) $c->high, $price, self::SCALE)) {
                $deepest = $rung;
            }
        }

        return $deepest;
    }

    private function tpHit(string $direction, Candle $c, string $tpPrice): bool
    {
        return $direction === 'LONG'
            ? Math::gte((string) $c->high, $tpPrice, self::SCALE)
            : Math::lte((string) $c->low, $tpPrice, self::SCALE);
    }

    private function slHit(string $direction, Candle $c, string $slPrice): bool
    {
        return $direction === 'LONG'
            ? Math::lte((string) $c->low, $slPrice, self::SCALE)
            : Math::gte((string) $c->high, $slPrice, self::SCALE);
    }

    private function trackClosestApproach(
        string $direction,
        Candle $c,
        string $currentTpPrice,
        ?string &$closestDiff,
        ?string &$closestPrice,
        ?string &$closestCandle,
    ): void {
        if ($direction === 'LONG') {
            if (Math::lt((string) $c->high, $currentTpPrice, self::SCALE)) {
                $diff = Math::sub($currentTpPrice, (string) $c->high, self::SCALE);
                if ($closestDiff === null || Math::lt($diff, $closestDiff, self::SCALE)) {
                    $closestDiff = $diff;
                    $closestPrice = (string) $c->high;
                    $closestCandle = (string) $c->candle_time_utc;
                }
            }

            return;
        }

        if (Math::gt((string) $c->low, $currentTpPrice, self::SCALE)) {
            $diff = Math::sub((string) $c->low, $currentTpPrice, self::SCALE);
            if ($closestDiff === null || Math::lt($diff, $closestDiff, self::SCALE)) {
                $closestDiff = $diff;
                $closestPrice = (string) $c->low;
                $closestCandle = (string) $c->candle_time_utc;
            }
        }
    }

    /**
     * @param  array<int, array{price: string, quantity: string, amount: string}>  $ladder
     */
    private function cumulativeQtyAtRung(string $marketQty, array $ladder, int $k): string
    {
        $sum = $marketQty;
        for ($i = 0; $i < $k && $i < count($ladder); $i++) {
            $sum = Math::add($sum, (string) $ladder[$i]['quantity'], self::SCALE);
        }

        return $sum;
    }

    /**
     * Computes the stop-loss price once at the rung-N promotion boundary.
     * Returns the formatted string price on success, or null when the
     * ladder math rejects the inputs (e.g. clamp to zero on extreme
     * low-price tokens) — the caller treats null as "skip SL checks".
     */
    private function resolveStopLossPrice(
        string $direction,
        string $anchorPrice,
        string $slPercent,
        string $cumQty,
        ExchangeSymbol $symbol,
    ): ?string {
        try {
            $sl = Kraite::calculateStopLossOrder(
                direction: $direction,
                anchorPrice: $anchorPrice,
                stopPercent: $slPercent,
                currentQty: $cumQty,
                exchangeSymbol: $symbol,
            );
        } catch (Throwable) {
            return null;
        }

        return (string) $sl['price'];
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function shouldIncludeRow(array $result, ?int $limitHit, bool $nonReboundableOnly): bool
    {
        if ($limitHit !== null && ((int) ($result['last_rung'] ?? 0)) < $limitHit) {
            return false;
        }

        if ($nonReboundableOnly) {
            return in_array($result['status'] ?? '', ['non-reboundable', 'stopped_out'], true);
        }

        return true;
    }

    private function validate(string $timeframe, string $margin, int $leverage, int $totalLimitOrders, int $daysToIgnore): void
    {
        if (! in_array($timeframe, ['1h', '4h', '12h'], true)) {
            throw new InvalidArgumentException("Unsupported timeframe: {$timeframe}. Allowed: 1h, 4h, 12h.");
        }
        if (! is_numeric($margin) || (float) $margin <= 0) {
            throw new InvalidArgumentException('Margin must be numeric and > 0.');
        }
        if ($leverage < 1) {
            throw new InvalidArgumentException('Leverage must be >= 1.');
        }
        if ($totalLimitOrders < 1) {
            throw new InvalidArgumentException('Total limit orders must be >= 1.');
        }
        if ($daysToIgnore < 0) {
            throw new InvalidArgumentException('daysToIgnore must be >= 0.');
        }
    }

    /**
     * Find the positional index within $all of the candle that matches the
     * requested minute-aligned timestamp. Returns array so the caller can
     * iterate with the same shape used in full-history mode.
     *
     * @return array<int, int>
     */
    private function findSingleCandleIndex(Collection $all, Carbon $target): array
    {
        $needle = $target->format('Y-m-d H:i:00');

        foreach ($all as $idx => $candle) {
            if (Carbon::parse((string) $candle->candle_time_utc)->format('Y-m-d H:i:00') === $needle) {
                return [$idx];
            }
        }

        return [];
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, totals: array<string, int>, meta: array<string, mixed>}
     */
    private function emptyResult(string $reason): array
    {
        return [
            'rows' => [],
            'totals' => [
                'candles' => 0,
                'stops' => 0,
                'non_reboundable' => 0,
                'tp_market_only' => 0,
                'reboundable' => 0,
            ],
            'meta' => ['reason' => $reason],
        ];
    }

    private function ctString($candleTime): string
    {
        if ($candleTime instanceof Carbon) {
            return $candleTime->toDateTimeString();
        }

        return Carbon::parse((string) $candleTime)->toDateTimeString();
    }
}
