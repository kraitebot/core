<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Backtest;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Kraite\Core\Enums\BacktestTimeframe;
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
 *        - inconclusive              — walker ran out of forward data without TP or SL
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
     * Don't START sims for candles inside this trailing window — they
     * wouldn't have enough forward data to resolve and would all come back
     * "inconclusive". The walker still uses the last N days as forward data
     * for older sims; this only gates which candles can be entries.
     * Bypassed when the caller requests a specific candle.
     */
    private const MIN_START_BUFFER_DAYS = 15;

    /**
     * Number of equal time buckets the window is sliced into so the caller
     * can see per-regime stability (a config that "passes" globally may be
     * 40% SL in one bucket and 0% in another — regime-blind averages hide
     * that). Six buckets = ~4 months each over a 2y window, which is a
     * reasonable granularity to separate trend / range / chop phases
     * without producing too-small samples per slice.
     */
    private const REGIME_BUCKETS = 6;

    /**
     * Simulate the ladder against historical candles for one symbol.
     *
     * @param  ExchangeSymbol  $symbol  Symbol to test (candles looked up by symbol + timeframe).
     * @param  string  $timeframe  Candle timeframe: 1h / 4h / 1d.
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
     * @param  Carbon|null  $specificCandle  Run the sim against this single candle only.
     * @param  Carbon|null  $since  Limit the candle window: include only candles with candle_time_utc >= $since. Null = walk everything.
     * @return array{
     *   rows: array<int, array<string, mixed>>,
     *   totals: array<string, mixed>,
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
        ?Carbon $specificCandle = null,
        ?Carbon $since = null,
    ): array {
        $this->validate($timeframe, $margin, $leverage, $totalLimitOrders, $daysToIgnore);

        $tz = config('app.timezone', 'UTC');
        $displayCutoff = Carbon::now($tz)->subDays($daysToIgnore);

        $all = Candle::query()
            ->where('exchange_symbol_id', $symbol->id)
            ->where('timeframe', $timeframe)
            ->when($since !== null, fn ($q) => $q->where('candle_time_utc', '>=', $since->toDateTimeString()))
            ->orderBy('candle_time_utc')
            ->get(['id', 'open', 'high', 'low', 'close', 'candle_time_utc']);

        if ($all->isEmpty()) {
            return $this->emptyResult('No candles present for this symbol / timeframe.');
        }

        $dailyAmplitude = (new BacktestDailyAmplitude)->calculate($all);

        // Preserve original positional index into $all so single-candle
        // mode still walks forward from the right starting point.
        $startIndices = $specificCandle !== null
            ? $this->findSingleCandleIndex($all, $specificCandle)
            : range(0, $all->count() - 1);

        // Trim entries inside the trailing buffer — sims started this close
        // to now() can't resolve (walker has nothing to walk through), so
        // they all come back inconclusive. Bypassed when the caller asks
        // for a specific candle. The forward walker still uses these
        // candles as forward data for older sims.
        if ($specificCandle === null) {
            $startCutoff = Carbon::now('UTC')->subDays(self::MIN_START_BUFFER_DAYS)->toDateTimeString();
            $startIndices = array_values(array_filter(
                $startIndices,
                fn ($idx) => (string) $all[$idx]->candle_time_utc <= $startCutoff,
            ));
        }

        if (empty($startIndices)) {
            return $this->emptyResult('No start candles outside the '.self::MIN_START_BUFFER_DAYS.'-day trailing buffer.');
        }

        $rows = [];
        $totals = [
            'candles' => 0,
            'stops' => 0,
            'tp_market_only' => 0,
            'reboundable' => 0,
            'inconclusive' => 0,
            'skipped' => 0,
        ];

        // Per-sim accumulators for the analytics we compute at the end.
        $rungDistribution = array_fill(0, $totalLimitOrders + 1, 0);
        $rungDepthSum = 0;
        $rungDepthCount = 0;
        $maeSum = 0.0;
        $maeMax = 0.0;
        $maeCount = 0;
        $candlesToProfit = [];
        // Per-direction list of extra-SL deltas across stopped sims. Used to
        // compute coverage percentiles (25/50/75/100%) — operator sees what
        // SL setting would have absorbed each tier of stops, separately for
        // LONG and SHORT (a token can have very different bleed profiles
        // up vs down).
        $extraSlByDirection = ['LONG' => [], 'SHORT' => []];

        // Regime buckets — slice the window into N equal time chunks so we
        // can see whether a config is stable or just averaging bull + bear.
        $totalStartCandles = count($startIndices);
        $bucketSize = max(1, (int) ceil($totalStartCandles / self::REGIME_BUCKETS));
        $regimeBuckets = [];
        for ($b = 0; $b < self::REGIME_BUCKETS; $b++) {
            $regimeBuckets[$b] = [
                'from' => null,
                'to' => null,
                'candles' => 0,
                'stops' => 0,
                'tp_market_only' => 0,
                'reboundable' => 0,
            ];
        }

        foreach ($startIndices as $localIdx => $startIdx) {
            /** @var Candle $startCandle */
            $startCandle = $all[$startIdx];
            $startCandleStr = (string) $startCandle->candle_time_utc;

            $totals['candles']++;
            $bucketIdx = min(self::REGIME_BUCKETS - 1, intdiv($localIdx, $bucketSize));
            $regimeBuckets[$bucketIdx]['candles']++;
            if ($regimeBuckets[$bucketIdx]['from'] === null) {
                $regimeBuckets[$bucketIdx]['from'] = $startCandleStr;
            }
            $regimeBuckets[$bucketIdx]['to'] = $startCandleStr;

            $suppress = $specificCandle === null
                && Carbon::parse($startCandleStr)->gte($displayCutoff);

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
                    'tp_hit_from_market_only' => $totals['tp_market_only']++,
                    'reboundable' => $totals['reboundable']++,
                    'inconclusive' => $totals['inconclusive']++,
                    // Sizing/ladder rejections. Counted so a run where EVERY sim
                    // skipped is distinguishable from a clean zero-stop run —
                    // otherwise "0 stops" reads as approvable when nothing was
                    // actually simulated.
                    'skipped' => $totals['skipped']++,
                    default => null,
                };

                if ($status === 'stopped_out' && isset($result['extra_sl_pct']) && $result['extra_sl_pct'] !== null) {
                    $dirKey = $result['direction'] ?? null;
                    if ($dirKey === 'LONG' || $dirKey === 'SHORT') {
                        $extraSlByDirection[$dirKey][] = (float) $result['extra_sl_pct'];
                    }
                }

                // Skipped / error sims shouldn't pollute the analytics.
                if (in_array($status, ['stopped_out', 'tp_hit_from_market_only', 'reboundable', 'inconclusive'], true)) {
                    $rung = (int) ($result['last_rung'] ?? 0);
                    if (isset($rungDistribution[$rung])) {
                        $rungDistribution[$rung]++;
                    }
                    $rungDepthSum += $rung;
                    $rungDepthCount++;

                    $mae = (float) ($result['mae_pct'] ?? 0);
                    $maeSum += $mae;
                    $maeMax = max($maeMax, $mae);
                    $maeCount++;

                    if (isset($result['candles_to_profit']) && $result['candles_to_profit'] !== null) {
                        $candlesToProfit[] = (int) $result['candles_to_profit'];
                    }

                    $bucketStatusKey = match ($status) {
                        'stopped_out' => 'stops',
                        'tp_hit_from_market_only' => 'tp_market_only',
                        'reboundable' => 'reboundable',
                        'inconclusive' => null,
                    };
                    if ($bucketStatusKey !== null) {
                        $regimeBuckets[$bucketIdx][$bucketStatusKey]++;
                    }
                }

                if (! $suppress && $this->shouldIncludeRow($result, $limitHit)) {
                    $rows[] = $result;
                }
            }
        }

        $analytics = $this->computeAnalytics(
            $totals,
            $rungDistribution,
            $rungDepthSum,
            $rungDepthCount,
            $maeSum,
            $maeMax,
            $maeCount,
            $candlesToProfit,
            $regimeBuckets,
        );

        // SL coverage tiers per direction — for each of 25/50/75/100%, what
        // SL setting (absolute + delta from form input) would have absorbed
        // that share of stops. Built from the sorted extra-SL values seen in
        // simulation; `total: X%` means setting SL to X% covers ALL stops.
        $slPercentFloat = (float) $slPercent;
        $slCoverage = [
            'LONG' => $this->buildSlCoverageTiers($extraSlByDirection['LONG'], $slPercentFloat),
            'SHORT' => $this->buildSlCoverageTiers($extraSlByDirection['SHORT'], $slPercentFloat),
        ];

        return [
            'rows' => $rows,
            'totals' => array_merge($totals, $analytics['totals'], [
                'max_daily_amplitude_pct' => $dailyAmplitude['percentage'],
                'max_daily_amplitude_date' => $dailyAmplitude['date'],
                'sl_coverage' => $slCoverage,
            ]),
            'regimes' => $analytics['regimes'],
            'meta' => [
                'symbol' => $symbol->parsed_trading_pair,
                'timeframe' => $timeframe,
                'total_candles_in_db' => $all->count(),
                'display_cutoff' => $displayCutoff->toDateTimeString(),
                'window_since' => $since?->toDateTimeString(),
                'tp_percent' => $tpPercent,
                'sl_percent' => $slPercent,
                'gap_long_percent' => $gapLongPercent ?? (string) $symbol->percentage_gap_long,
                'gap_short_percent' => $gapShortPercent ?? (string) $symbol->percentage_gap_short,
                'margin' => $margin,
                'leverage' => $leverage,
                'total_limit_orders' => $totalLimitOrders,
                'multipliers' => $multipliers ?? ($symbol->limit_quantity_multipliers ?? [2, 2, 2, 2]),
                'skip_stop_loss' => $skipStopLoss,
                'days_to_ignore' => $daysToIgnore,
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
                'mae_pct' => 0.0,
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
                'mae_pct' => 0.0,
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
                'mae_pct' => 0.0,
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

        // Max adverse excursion — biggest drawdown (LONG) / run-up (SHORT)
        // the sim saw while held, as % of entry. Captures how bad it got
        // even when the trade eventually rebounds; a 95%-pass config that
        // swings 8% against you on the way is very different from one that
        // never drifts more than 1%.
        $entryFloat = (float) $entryRef;
        $maeAbs = 0.0;

        for ($i = $startIdx + 1; $i < $allCandles->count(); $i++) {
            /** @var Candle $c */
            $c = $allCandles[$i];

            if ($entryFloat > 0.0) {
                $adverse = $direction === 'LONG'
                    ? ($entryFloat - (float) $c->low) / $entryFloat
                    : ((float) $c->high - $entryFloat) / $entryFloat;
                if ($adverse > $maeAbs) {
                    $maeAbs = $adverse;
                }
            }

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
                        'mae_pct' => round($maeAbs * 100, 3),
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
                    // After SL fires the position is closed — no further
                    // exposure. But the operator still wants to know how
                    // bad the bleed would have continued: walk every
                    // remaining candle for the worst adverse price (low
                    // for LONG, high for SHORT). Surfaces SL timing —
                    // tight SLs that fired right before recovery vs SLs
                    // that fired into a continued crash.
                    [$maxPainPrice, $maxPainCandle] = $this->trackMaxPainAfterSl(
                        $direction,
                        $allCandles,
                        $i,
                    );

                    // Format with the exchange's price precision so trailing
                    // zeros from the candle decimal column don't pollute the
                    // UI / message ("0.078850000000" → "0.07885").
                    $maxPainFormatted = $maxPainPrice !== null
                        ? api_format_price($maxPainPrice, $symbol)
                        : null;

                    // Extra SL % the operator would have needed (on top of the
                    // current slPercent) to absorb the post-SL bleed. Anchored
                    // off the rung-N price — same reference the live SL uses —
                    // so the number stacks directly on slPercent: "current 3.5%
                    // + needed 3.11% = 6.61% to have stayed in".
                    $extraSlPct = null;
                    if ($maxPainPrice !== null) {
                        $anchor = (float) $priceByRung[$totalLimitOrders];
                        $sl = (float) $cachedSlPrice;
                        $pain = (float) $maxPainPrice;
                        if ($anchor > 0.0) {
                            $deltaFromAnchor = $direction === 'LONG'
                                ? ($sl - $pain) / $anchor * 100.0
                                : ($pain - $sl) / $anchor * 100.0;
                            if ($deltaFromAnchor > 0.0) {
                                $extraSlPct = round($deltaFromAnchor, 2);
                            }
                        }
                    }

                    // Bold prices via inline <b> tags — the row note column
                    // renders this with x-html so the SL and max-pain numbers
                    // pop. Message is fully server-generated (no user input)
                    // so the HTML embed is safe.
                    $painSegment = $maxPainFormatted !== null
                        ? ('max pain: <b>'.$maxPainFormatted.'</b>'
                            .($extraSlPct !== null ? sprintf(', SL needed: +%s%%', $extraSlPct) : ''))
                        : null;

                    $slLabel = $painSegment !== null
                        ? sprintf('<b>%s</b> (%s)', $cachedSlPrice, $painSegment)
                        : sprintf('<b>%s</b>', $cachedSlPrice);

                    $message = sprintf(
                        'Stopped out at %s on %s after last rung (N=%d) touched at %s.',
                        $slLabel,
                        $this->ctString($c->candle_time_utc),
                        $totalLimitOrders,
                        $this->ctString($allCandles[$lastTouchIdx]->candle_time_utc)
                    );

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
                        'message' => $message,
                        'mae_pct' => round($maeAbs * 100, 3),
                        'sl_price' => $cachedSlPrice,
                        'sl_candle' => $this->ctString($c->candle_time_utc),
                        'max_pain_price' => $maxPainFormatted,
                        'max_pain_candle' => $maxPainCandle !== null ? $this->ctString($maxPainCandle) : null,
                        'extra_sl_pct' => $extraSlPct,
                    ];
                }
            }
        }

        // End of data — sim never resolved (no TP hit, no SL hit, no rung-N
        // promotion path that would have triggered SL). Tagged "inconclusive"
        // so the operator can see how many sims couldn't finish in the window.
        $note = 'Inconclusive: walker ran out of forward data before TP or SL fired. Last TP target was '.($currentTpPrice ?? 'N/A');
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
            'status' => 'inconclusive',
            'message' => $note,
            'mae_pct' => round($maeAbs * 100, 3),
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

    /**
     * Build SL-coverage tiers from the per-direction list of extra-SL %
     * values observed across stopped sims. Each tier reports the SL setting
     * (absolute + delta from form input) that would have absorbed that share
     * of stops. Empty list → null tiers (no stops to cover).
     *
     * @param  array<int, float>  $extras  Sorted-by-value extra-SL deltas
     * @return array<string, array{pct: float|null, delta: float|null}>
     */
    private function buildSlCoverageTiers(array $extras, float $slPercentInput): array
    {
        sort($extras);
        $count = count($extras);

        $emptyTier = ['pct' => null, 'delta' => null];
        $tiers = [
            'p25' => $emptyTier,
            'p50' => $emptyTier,
            'p75' => $emptyTier,
            'p100' => $emptyTier,
            'count' => $count,
        ];

        if ($count === 0) {
            return $tiers;
        }

        $pickAt = function (float $coverage) use ($extras, $count): float {
            // Smallest extra-SL value that covers `coverage` share of stops
            // when set as the SL. coverage=0.25 → ceil(N*0.25)-1 index.
            $idx = max(0, (int) ceil($count * $coverage) - 1);
            $idx = min($count - 1, $idx);

            return $extras[$idx];
        };

        // Sequential array — PHP truncates float keys to int (0.25→0, 0.50→0,
        // 0.75→0) and silently collapses them, so we iterate pairs instead.
        $tierSpecs = [
            ['key' => 'p25', 'coverage' => 0.25],
            ['key' => 'p50', 'coverage' => 0.50],
            ['key' => 'p75', 'coverage' => 0.75],
            ['key' => 'p100', 'coverage' => 1.00],
        ];
        foreach ($tierSpecs as $spec) {
            $delta = $pickAt($spec['coverage']);
            $tiers[$spec['key']] = [
                'pct' => round($slPercentInput + $delta, 2),
                'delta' => round($delta, 2),
            ];
        }

        return $tiers;
    }

    /**
     * Track the worst adverse price after SL fires through end of dataset.
     * For LONG: lowest low. For SHORT: highest high. Compared in plain float
     * since the metric is informational, not used in fill decisions — full
     * Math::lt precision would be overkill.
     *
     * @return array{0: string|null, 1: mixed} [max-pain price, candle_time of that bar]
     */
    private function trackMaxPainAfterSl(string $direction, Collection $allCandles, int $slFireIdx): array
    {
        $painPrice = null;
        $painCandle = null;

        for ($j = $slFireIdx; $j < $allCandles->count(); $j++) {
            /** @var Candle $cc */
            $cc = $allCandles[$j];

            if ($direction === 'LONG') {
                $candidate = (float) $cc->low;
                if ($painPrice === null || $candidate < (float) $painPrice) {
                    $painPrice = (string) $cc->low;
                    $painCandle = $cc->candle_time_utc;
                }
            } else {
                $candidate = (float) $cc->high;
                if ($painPrice === null || $candidate > (float) $painPrice) {
                    $painPrice = (string) $cc->high;
                    $painCandle = $cc->candle_time_utc;
                }
            }
        }

        return [$painPrice, $painCandle];
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
     * Derive summary analytics from the per-sim accumulators. Splits out
     * into `totals` (flat counters merged into the main totals dict) and
     * `regimes` (per-bucket breakdown rendered as its own panel).
     *
     * @param  array<string, int>  $totals
     * @param  array<int, int>  $rungDistribution
     * @param  array<int, int>  $candlesToProfit
     * @param  array<int, array<string, mixed>>  $regimeBuckets
     * @return array{totals: array<string, mixed>, regimes: array<int, array<string, mixed>>}
     */
    private function computeAnalytics(
        array $totals,
        array $rungDistribution,
        int $rungDepthSum,
        int $rungDepthCount,
        float $maeSum,
        float $maeMax,
        int $maeCount,
        array $candlesToProfit,
        array $regimeBuckets,
    ): array {
        $totalSims = ($totals['candles'] ?? 0) * 2;
        $effectiveSims = max(1, $totalSims - ($totals['inconclusive'] ?? 0));

        $stopsPct = ($totals['stops'] ?? 0) / $effectiveSims * 100;
        $avgRungDepth = $rungDepthCount > 0 ? $rungDepthSum / $rungDepthCount : 0.0;

        // Composite risk score — lower is safer. Stops are the only "bad"
        // outcome we care about now (real capital loss). Rung depth adds a
        // capital-exposure component in the 0-N range.
        $riskScore = ($stopsPct * 3) + $avgRungDepth;

        $candlesToProfit = array_values($candlesToProfit);
        sort($candlesToProfit);
        $avgCtp = empty($candlesToProfit) ? null : array_sum($candlesToProfit) / count($candlesToProfit);
        $p95Ctp = empty($candlesToProfit) ? null : $candlesToProfit[min(count($candlesToProfit) - 1, (int) floor(count($candlesToProfit) * 0.95))];

        // Finalise regimes with per-bucket pass rate; skip empty buckets.
        $regimes = [];
        $worstPassRate = null;
        foreach ($regimeBuckets as $bucket) {
            if (($bucket['candles'] ?? 0) === 0) {
                continue;
            }
            $pass = ($bucket['tp_market_only'] ?? 0) + ($bucket['reboundable'] ?? 0);
            $fail = $bucket['stops'] ?? 0;
            $resolved = max(1, $pass + $fail);
            $passRate = $pass / $resolved * 100;
            $regimes[] = [
                'from' => $bucket['from'],
                'to' => $bucket['to'],
                'candles' => $bucket['candles'],
                'stops' => $bucket['stops'],
                'tp_market_only' => $bucket['tp_market_only'],
                'reboundable' => $bucket['reboundable'],
                'pass_rate' => round($passRate, 2),
            ];
            $worstPassRate = $worstPassRate === null ? $passRate : min($worstPassRate, $passRate);
        }

        // Overall token score — single comparable 0-100 value. Stops dominate
        // (real losses); rung depth + Max MAE measure capital exposure;
        // worst-bucket guards against regime fragility.
        $totalLimitOrders = max(1, count($rungDistribution) - 1);
        $rungDepthRatio = $avgRungDepth / $totalLimitOrders;

        // Stops are the dominant signal — they're real $ losses. Other
        // factors are tiebreakers among configs with similar stop rates.
        // Calibration target: ~10% stops should land in F (≤30), ~5% in
        // D (~60), ~2% in B (~80), ~0.5% in A (~90+). Old 3× weight let
        // 12% stops still grade B because depth/MAE/worst-bucket chipped
        // tiny deductions and stops alone weren't decisive.
        //
        // Sample-size penalty: a backtest over 50 candles is statistically
        // unreliable regardless of how clean the rest of the metrics look —
        // ramp from -30 at 0 candles to 0 at 180+. 180 = ~half a year of 1d
        // data or ~7.5 days of 1h data; below that the score should reflect
        // "not enough evidence" rather than overstated confidence.
        $candles = (int) ($totals['candles'] ?? 0);
        $sampleSizePenalty = $candles >= 180 ? 0.0 : (180 - $candles) / 180.0 * 30.0;

        $overallScore = 100.0
            - ($stopsPct * 5)
            - ($rungDepthRatio * 100 * 0.05)
            - (min($maeMax, 100.0) * 0.10)
            - ((100.0 - ($worstPassRate ?? 100.0)) * 0.3)
            - $sampleSizePenalty;

        $overallScore = max(0.0, min(100.0, $overallScore));

        // The operator's decision rule is ABSOLUTE stop-loss hits (< 5
        // approve · 5–10 adjust · > 10 reject). The percentage-weighted
        // score above can grade a 16-stop run "B" when the sample is large
        // (16 of ~1400 sims ≈ 1% → tiny deduction), directly contradicting
        // the "recommend reject" proposal rendered beside it. Cap the score
        // so the letter can never outrank the decision band: more than 10
        // stops grades at best D (reject territory), 5–10 at best C.
        $absoluteStops = (int) ($totals['stops'] ?? 0);
        if ($absoluteStops > 10) {
            $overallScore = min($overallScore, 69.0);
        } elseif ($absoluteStops >= 5) {
            $overallScore = min($overallScore, 79.0);
        }

        $grade = match (true) {
            $overallScore >= 90 => 'A',
            $overallScore >= 80 => 'B',
            $overallScore >= 70 => 'C',
            $overallScore >= 60 => 'D',
            default => 'F',
        };

        $verdict = match ($grade) {
            'A' => 'Excellent — safe, efficient, stable across regimes.',
            'B' => 'Good — minor concerns, mostly fine to run.',
            'C' => 'Acceptable — tune TP/SL/gap or watch exposure.',
            'D' => 'Marginal — elevated risk signals; reconsider.',
            'F' => 'Avoid — one or more critical flags on this config.',
        };

        return [
            'totals' => [
                'overall_score' => round($overallScore, 1),
                'grade' => $grade,
                'verdict' => $verdict,
                'risk_score' => round($riskScore, 2),
                'avg_rung_depth' => round($avgRungDepth, 2),
                'rung_distribution' => $rungDistribution,
                'avg_mae_pct' => $maeCount > 0 ? round($maeSum / $maeCount, 3) : 0.0,
                'max_mae_pct' => round($maeMax, 3),
                'sample_size_penalty' => round($sampleSizePenalty, 1),
                'sample_size_threshold' => 180,
                'avg_candles_to_profit' => $avgCtp !== null ? round($avgCtp, 2) : null,
                'p95_candles_to_profit' => $p95Ctp,
                'worst_bucket_pass_rate' => $worstPassRate !== null ? round($worstPassRate, 2) : null,
            ],
            'regimes' => $regimes,
        ];
    }

    /**
     * Failures-only filter. Reboundable and tp_hit_from_market_only verdicts
     * are the happy path — no row is emitted for them. Absence of a row
     * means "that sim resolved fine", which is the operator's mental model
     * when scanning results.
     *
     * @param  array<string, mixed>  $result
     */
    private function shouldIncludeRow(array $result, ?int $limitHit): bool
    {
        if ($limitHit !== null && ((int) ($result['last_rung'] ?? 0)) < $limitHit) {
            return false;
        }

        return in_array($result['status'] ?? '', ['stopped_out', 'inconclusive'], true);
    }

    private function validate(string $timeframe, string $margin, int $leverage, int $totalLimitOrders, int $daysToIgnore): void
    {
        $allowed = BacktestTimeframe::values();
        if (! in_array($timeframe, $allowed, true)) {
            throw new InvalidArgumentException("Unsupported timeframe: {$timeframe}. Allowed: ".implode(', ', $allowed).'.');
        }
        if (! is_numeric($margin) || Math::lte((string) $margin, '0')) {
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
     * @return array{rows: array<int, array<string, mixed>>, totals: array<string, mixed>, meta: array<string, mixed>}
     */
    private function emptyResult(string $reason): array
    {
        return [
            'rows' => [],
            'totals' => [
                'candles' => 0,
                'stops' => 0,
                'tp_market_only' => 0,
                'reboundable' => 0,
                'inconclusive' => 0,
                'skipped' => 0,
                'overall_score' => null,
                'grade' => null,
                'verdict' => null,
                'risk_score' => 0,
                'avg_rung_depth' => 0,
                'rung_distribution' => [],
                'avg_mae_pct' => 0,
                'max_mae_pct' => 0,
                'max_daily_amplitude_pct' => 0,
                'max_daily_amplitude_date' => null,
                'avg_candles_to_profit' => null,
                'p95_candles_to_profit' => null,
                'worst_bucket_pass_rate' => null,
            ],
            'regimes' => [],
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
