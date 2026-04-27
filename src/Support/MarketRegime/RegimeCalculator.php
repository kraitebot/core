<?php

declare(strict_types=1);

namespace Kraite\Core\Support\MarketRegime;

use Kraite\Core\Enums\RegimeBand;

/**
 * Black Swan Composite Score (BSCS) — pure-PHP signal math.
 *
 * Five binary sub-signals computed from 1h kline data on BTC + 4 reference
 * alts (ETH, SOL, BNB, XRP). Each fires (1) or misses (0); composite = sum
 * of fired × 20 → range [0, 100].
 *
 * Spec: `~/docs/kraite/black-swan-logic.md` "The Five Sub-signals" +
 * "Composite Score" + locked decision #1 (block threshold = 80).
 *
 * Backtested against six confirmed crypto black swan events (Mar 2020,
 * May 2021, Jun 2022, Nov 2022, Aug 2024, Oct 2025) — see the spec doc
 * "Research Summary" for the cross-event grid. The Python research project
 * at `~/blackswan/` is the validation oracle.
 *
 * Inputs are arrays of OHLCV bars in chronological order (oldest first):
 *
 *   ['open' => ..., 'high' => ..., 'low' => ..., 'close' => ...,
 *    'volume' => ..., 'timestamp' => <ms>]
 *
 * BTC bars are required for all five sub-signals; ALT bars (keyed by token
 * — `ETH`, `SOL`, `BNB`, `XRP`) are only consumed by `corr_regime`.
 */
final class RegimeCalculator
{
    private const HOURS_PER_DAY = 24;

    private const BASELINE_DAYS = 14;

    private const CORR_WINDOW_HOURS = 48;

    /**
     * @return array{vol_expansion: float, range_blowout: float, corr_regime: float, rejection_pct: float, fut_vol: float}
     */
    public static function defaultThresholds(): array
    {
        return [
            'vol_expansion' => 1.30,
            'range_blowout' => 1.50,
            'corr_regime' => 0.70,
            'rejection_pct' => -5.0,
            'fut_vol' => 1.20,
        ];
    }

    /**
     * Compute the five raw sub-signal values from BTC + ALT klines.
     *
     * @param  list<array<string, mixed>>  $btc
     * @param  array<string, list<array<string, mixed>>>  $alts
     * @return array{vol_expansion: float, range_blowout: float, corr_regime: float, rejection_pct: float, fut_vol: float}
     */
    public static function computeSubSignalValues(array $btc, array $alts): array
    {
        return [
            'vol_expansion' => self::volExpansion($btc),
            'range_blowout' => self::rangeBlowout($btc),
            'corr_regime' => self::corrRegime($btc, $alts),
            'rejection_pct' => self::rejectionPct($btc),
            'fut_vol' => self::futVolHot($btc),
        ];
    }

    /**
     * Threshold the raw values into binary fire/miss flags.
     *
     * @param  array{vol_expansion: float, range_blowout: float, corr_regime: float, rejection_pct: float, fut_vol: float}  $values
     * @param  array{vol_expansion: float, range_blowout: float, corr_regime: float, rejection_pct: float, fut_vol: float}  $thresholds
     * @return array{vol_expansion_fired: bool, range_blowout_fired: bool, corr_regime_fired: bool, rejection_pct_fired: bool, fut_vol_fired: bool}
     */
    public static function applyThresholds(array $values, array $thresholds): array
    {
        return [
            'vol_expansion_fired' => $values['vol_expansion'] > $thresholds['vol_expansion'],
            'range_blowout_fired' => $values['range_blowout'] > $thresholds['range_blowout'],
            'corr_regime_fired' => $values['corr_regime'] > $thresholds['corr_regime'],
            // rejection_pct is signed and a NEGATIVE threshold; fires when value is MORE negative.
            'rejection_pct_fired' => $values['rejection_pct'] < $thresholds['rejection_pct'],
            'fut_vol_fired' => $values['fut_vol'] > $thresholds['fut_vol'],
        ];
    }

    /**
     * Sum fired flags × 20 → [0, 100].
     *
     * @param  array{vol_expansion_fired: bool, range_blowout_fired: bool, corr_regime_fired: bool, rejection_pct_fired: bool, fut_vol_fired: bool}  $fired
     */
    public static function compositeScore(array $fired): int
    {
        $count = 0;
        foreach ($fired as $flag) {
            if ($flag === true) {
                $count++;
            }
        }

        return $count * 20;
    }

    public static function bandFromScore(int $score): RegimeBand
    {
        return RegimeBand::fromScore($score);
    }

    /**
     * Sub-signal #1: stdev of last-24h log returns / stdev of last-14d
     * log returns. Vol breaking out of compressed regime → ratio > 1.
     *
     * @param  list<array<string, mixed>>  $btc
     */
    private static function volExpansion(array $btc): float
    {
        $closes = self::extractColumn($btc, 'close');
        $returns = self::logReturns($closes);

        // Need at least one full day of returns + meaningful baseline.
        // Log-returns yields N−1 values from N bars; allow the −1 slack.
        if (count($returns) < (self::HOURS_PER_DAY * self::BASELINE_DAYS) - 1) {
            return 0.0;
        }

        $last24 = array_slice($returns, -self::HOURS_PER_DAY);
        $last14d = array_slice($returns, -self::HOURS_PER_DAY * self::BASELINE_DAYS);

        $stdev24 = self::stdev($last24);
        $stdev14d = self::stdev($last14d);

        return $stdev14d > 0.0 ? $stdev24 / $stdev14d : 0.0;
    }

    /**
     * Sub-signal #2: max( (high-low) / low ) over last 24 bars / mean of
     * the same over last 14 days. Intraday range exploding above baseline.
     *
     * @param  list<array<string, mixed>>  $btc
     */
    private static function rangeBlowout(array $btc): float
    {
        $bars = array_slice($btc, -self::HOURS_PER_DAY * self::BASELINE_DAYS);
        if (count($bars) < self::HOURS_PER_DAY * self::BASELINE_DAYS) {
            return 0.0;
        }

        $rangePcts = [];
        foreach ($bars as $bar) {
            $high = (float) $bar['high'];
            $low = (float) $bar['low'];
            $rangePcts[] = $low > 0.0 ? ($high - $low) / $low : 0.0;
        }

        $last24 = array_slice($rangePcts, -self::HOURS_PER_DAY);
        $max24 = max($last24);
        $mean14d = array_sum($rangePcts) / count($rangePcts);

        return $mean14d > 0.0 ? $max24 / $mean14d : 0.0;
    }

    /**
     * Sub-signal #3: mean off-diagonal Pearson correlation of 1h log
     * returns across BTC + 4 alts, rolling 48h. When everything moves
     * together, diversification breaks → risk-off mode.
     *
     * @param  list<array<string, mixed>>  $btc
     * @param  array<string, list<array<string, mixed>>>  $alts
     */
    private static function corrRegime(array $btc, array $alts): float
    {
        $btcReturns = self::lastNReturns(self::extractColumn($btc, 'close'), self::CORR_WINDOW_HOURS);
        if (count($btcReturns) < self::CORR_WINDOW_HOURS) {
            return 0.0;
        }

        $series = ['BTC' => $btcReturns];
        foreach ($alts as $token => $bars) {
            $altReturns = self::lastNReturns(self::extractColumn($bars, 'close'), self::CORR_WINDOW_HOURS);
            if (count($altReturns) === self::CORR_WINDOW_HOURS) {
                $series[$token] = $altReturns;
            }
        }

        $tokens = array_keys($series);
        $n = count($tokens);
        if ($n < 2) {
            return 0.0;
        }

        $sum = 0.0;
        $pairs = 0;
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $sum += self::pearson($series[$tokens[$i]], $series[$tokens[$j]]);
                $pairs++;
            }
        }

        return $pairs > 0 ? $sum / $pairs : 0.0;
    }

    /**
     * Sub-signal #4: percentage drop of the most recent close from the
     * highest high in the last 14 days. Negative value when below; fires
     * when more negative than threshold (default −5%).
     *
     * @param  list<array<string, mixed>>  $btc
     */
    private static function rejectionPct(array $btc): float
    {
        $bars = array_slice($btc, -self::HOURS_PER_DAY * self::BASELINE_DAYS);
        if (count($bars) === 0) {
            return 0.0;
        }

        $highs = array_map(static fn (array $b): float => (float) $b['high'], $bars);
        $maxHigh = max($highs);
        $lastClose = (float) end($bars)['close'];

        return $maxHigh > 0.0 ? (($lastClose / $maxHigh) - 1.0) * 100.0 : 0.0;
    }

    /**
     * Sub-signal #5: ratio of last-24h volume to the 14-day daily average.
     * Leverage activity elevated above baseline → fuel loaded.
     *
     * Note: spec literally reads "24h sum ÷ 14d sum > 1.20" but that ratio
     * has a hard ceiling of 1.0 (24h ⊂ 14d). We compute against the 14-day
     * daily average instead, which is the only interpretation that makes
     * the >1.20 threshold meaningful (current day is 20% above the daily
     * average over the prior 14d).
     *
     * @param  list<array<string, mixed>>  $btc
     */
    private static function futVolHot(array $btc): float
    {
        $bars = array_slice($btc, -self::HOURS_PER_DAY * self::BASELINE_DAYS);
        if (count($bars) === 0) {
            return 0.0;
        }

        $totalVol = 0.0;
        foreach ($bars as $bar) {
            $totalVol += (float) $bar['volume'];
        }

        $last24Vol = 0.0;
        foreach (array_slice($bars, -self::HOURS_PER_DAY) as $bar) {
            $last24Vol += (float) $bar['volume'];
        }

        $dailyAvg14d = $totalVol / self::BASELINE_DAYS;

        return $dailyAvg14d > 0.0 ? $last24Vol / $dailyAvg14d : 0.0;
    }

    /**
     * @param  list<array<string, mixed>>  $bars
     * @return list<float>
     */
    private static function extractColumn(array $bars, string $field): array
    {
        $out = [];
        foreach ($bars as $bar) {
            $out[] = (float) $bar[$field];
        }

        return $out;
    }

    /**
     * Log returns: ln(p[i] / p[i-1]). Returns array length = closes - 1.
     *
     * @param  list<float>  $closes
     * @return list<float>
     */
    private static function logReturns(array $closes): array
    {
        $out = [];
        $prev = null;
        foreach ($closes as $price) {
            if ($prev !== null && $prev > 0.0 && $price > 0.0) {
                $out[] = log($price / $prev);
            }
            $prev = $price;
        }

        return $out;
    }

    /**
     * @param  list<float>  $closes
     * @return list<float>
     */
    private static function lastNReturns(array $closes, int $n): array
    {
        $returns = self::logReturns($closes);

        return array_slice($returns, -$n);
    }

    /**
     * Sample standard deviation (n-1 denominator).
     *
     * @param  list<float>  $values
     */
    private static function stdev(array $values): float
    {
        $n = count($values);
        if ($n < 2) {
            return 0.0;
        }

        $mean = array_sum($values) / $n;
        $sumSq = 0.0;
        foreach ($values as $v) {
            $diff = $v - $mean;
            $sumSq += $diff * $diff;
        }

        return sqrt($sumSq / ($n - 1));
    }

    /**
     * Pearson correlation between two equal-length series.
     *
     * @param  list<float>  $x
     * @param  list<float>  $y
     */
    private static function pearson(array $x, array $y): float
    {
        $n = count($x);
        if ($n === 0 || count($y) !== $n) {
            return 0.0;
        }

        $meanX = array_sum($x) / $n;
        $meanY = array_sum($y) / $n;

        $cov = 0.0;
        $varX = 0.0;
        $varY = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $dx = $x[$i] - $meanX;
            $dy = $y[$i] - $meanY;
            $cov += $dx * $dy;
            $varX += $dx * $dx;
            $varY += $dy * $dy;
        }

        $denom = sqrt($varX * $varY);

        return $denom > 0.0 ? $cov / $denom : 0.0;
    }
}
