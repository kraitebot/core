<?php

declare(strict_types=1);

namespace Kraite\Core\Support\MarketRegime;

use InvalidArgumentException;
use Kraite\Core\Support\Math;

/**
 * MarketShockCircuitBreaker — fast cascade-in-progress detector.
 *
 * Different role from BSCS: BSCS measures *setup fragility* on an hourly
 * cadence. This breaker measures *cascade in progress* on a 1-minute
 * cadence, reading 15m kline closes for BTC + 4 reference alts. When any
 * trigger rule fires, the consuming job arms the shared
 * `bscs_cooldown_until` column for `cooldown_hours`, which in turn blocks
 * new opens via `HasTradingGuards::canOpenPositions()`.
 *
 * Trigger rules (any one is sufficient):
 *
 *   1. btc_15m       — BTC dropped >= 3% across the last 15m bar.
 *   2. btc_1h        — BTC dropped >= 5% across the last 4 × 15m bars.
 *   3. alt_basket_1h — mean of ETH/SOL/BNB/XRP 1h moves <= -7%.
 *   4. corr_magnitude— BTC + 4 alts off-diagonal mean Pearson >= 0.85
 *                      AND BTC dropped >= 3% over 1h.
 *
 * Pure-PHP, no DB, no HTTP. The job layer feeds it from the `candles`
 * table after the `--reference-set --timeframe=15m` cron lands fresh
 * data every 15 min.
 *
 * @see ~/docs/kraite/black-swan-logic.md (Phase 2.1A)
 */
final class MarketShockCircuitBreaker
{
    /**
     * Default thresholds. Centralised for unit tests; production caller
     * (DetectMarketShockJob) overrides via config('kraite.market_regime.shock').
     *
     * @return array{btc_15m_pct: float, btc_1h_pct: float, alt_basket_1h_pct: float, corr_1h: float, magnitude_pct: float}
     */
    public static function defaultThresholds(): array
    {
        return [
            'btc_15m_pct' => -3.0,
            'btc_1h_pct' => -5.0,
            'alt_basket_1h_pct' => -7.0,
            'corr_1h' => 0.85,
            'magnitude_pct' => 3.0,
        ];
    }

    /**
     * Evaluate the breaker against fresh klines.
     *
     * The bar-offset parameters adapt the same rules to any bar spacing:
     * the default (1, 4, 12) reads 15m klines; the live mark-price path
     * passes (15, 60, 61) to read 1-minute samples with identical
     * window semantics — "15 minutes" and "1 hour" stay 15 minutes and
     * 1 hour regardless of the underlying bar size.
     *
     * @param  list<array{close: string, timestamp: int}>  $btcBars  newest-LAST
     * @param  array<string, list<array{close: string, timestamp: int}>>  $altBars  same shape, keyed by token (ETH/SOL/BNB/XRP)
     * @param  array{btc_15m_pct: float, btc_1h_pct: float, alt_basket_1h_pct: float, corr_1h: float, magnitude_pct: float}|null  $thresholds
     * @param  int  $shortOffset  bars spanning the "15m" window
     * @param  int  $longOffset  bars spanning the "1h" window
     * @param  int  $corrWindow  bars feeding the correlation returns series
     * @return array{
     *   fired: bool,
     *   rules_triggered: list<string>,
     *   btc_15m_pct: ?float,
     *   btc_1h_pct: ?float,
     *   alt_basket_1h_pct: ?float,
     *   corr_1h: ?float,
     *   thresholds: array<string, float>
     * }
     */
    public static function evaluate(
        array $btcBars,
        array $altBars,
        ?array $thresholds = null,
        int $shortOffset = 1,
        int $longOffset = 4,
        int $corrWindow = 12,
    ): array {
        $thresholds ??= self::defaultThresholds();
        self::validateThresholds($thresholds);

        $btc15m = self::priorBarPct($btcBars, $shortOffset);
        $btc1h = self::priorBarPct($btcBars, $longOffset);

        $altMoves = [];
        foreach ($altBars as $bars) {
            $move = self::priorBarPct($bars, $longOffset);
            if ($move !== null) {
                $altMoves[] = $move;
            }
        }
        $altBasket1h = $altMoves === [] ? null : array_sum($altMoves) / count($altMoves);

        $corr1h = self::correlation($btcBars, $altBars, $corrWindow);

        $rulesTriggered = [];

        if ($btc15m !== null && $btc15m <= $thresholds['btc_15m_pct']) {
            $rulesTriggered[] = 'btc_15m';
        }
        if ($btc1h !== null && $btc1h <= $thresholds['btc_1h_pct']) {
            $rulesTriggered[] = 'btc_1h';
        }
        if ($altBasket1h !== null && $altBasket1h <= $thresholds['alt_basket_1h_pct']) {
            $rulesTriggered[] = 'alt_basket_1h';
        }
        if ($corr1h !== null
            && $btc1h !== null
            && $corr1h >= $thresholds['corr_1h']
            && $btc1h <= -$thresholds['magnitude_pct']
        ) {
            $rulesTriggered[] = 'corr_magnitude';
        }

        return [
            'fired' => $rulesTriggered !== [],
            'rules_triggered' => $rulesTriggered,
            'btc_15m_pct' => $btc15m,
            'btc_1h_pct' => $btc1h,
            'alt_basket_1h_pct' => $altBasket1h,
            'corr_1h' => $corr1h,
            'thresholds' => $thresholds,
        ];
    }

    /**
     * @param  array{btc_15m_pct: float, btc_1h_pct: float, alt_basket_1h_pct: float, corr_1h: float, magnitude_pct: float}  $thresholds
     */
    private static function validateThresholds(array $thresholds): void
    {
        foreach (['btc_15m_pct', 'btc_1h_pct', 'alt_basket_1h_pct'] as $key) {
            if (! is_finite($thresholds[$key]) || $thresholds[$key] >= 0.0) {
                throw new InvalidArgumentException("Market shock threshold {$key} must be a finite negative percentage.");
            }
        }

        if (! is_finite($thresholds['corr_1h'])
            || $thresholds['corr_1h'] < -1.0
            || $thresholds['corr_1h'] > 1.0) {
            throw new InvalidArgumentException('Market shock threshold corr_1h must be between -1 and 1.');
        }

        if (! is_finite($thresholds['magnitude_pct']) || $thresholds['magnitude_pct'] <= 0.0) {
            throw new InvalidArgumentException('Market shock threshold magnitude_pct must be a finite positive percentage.');
        }
    }

    /**
     * Percentage move from the bar `$nBack` positions before the latest
     * to the latest close. Returns null if the array is too short.
     *
     * @param  list<array<string, mixed>>  $bars  newest-LAST
     */
    private static function priorBarPct(array $bars, int $nBack): ?float
    {
        $count = count($bars);
        if ($count < $nBack + 1) {
            return null;
        }

        $latest = (string) $bars[$count - 1]['close'];
        $prior = (string) $bars[$count - 1 - $nBack]['close'];

        if (Math::lte($prior, '0')) {
            return null;
        }

        return (float) Math::mul(Math::div(Math::sub($latest, $prior), $prior, 12), '100');
    }

    /**
     * Mean off-diagonal Pearson correlation across BTC + alt log-returns
     * over the last `$window` bars (default 12 = 3h of 15m data, gives
     * 11 returns per token). Returns null when any series is too short.
     *
     * @param  list<array<string, mixed>>  $btcBars
     * @param  array<string, list<array<string, mixed>>>  $altBars
     */
    private static function correlation(array $btcBars, array $altBars, int $window = 12): ?float
    {
        $btcReturns = self::lastNReturns(self::extractCloses($btcBars), $window);
        if (count($btcReturns) < $window - 1) {
            return null;
        }

        $series = ['BTC' => $btcReturns];
        foreach ($altBars as $token => $bars) {
            $returns = self::lastNReturns(self::extractCloses($bars), $window);
            if (count($returns) === $window - 1) {
                $series[$token] = $returns;
            }
        }

        $tokens = array_keys($series);
        $n = count($tokens);
        if ($n < 2) {
            return null;
        }

        $sum = 0.0;
        $pairs = 0;
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $correlation = self::pearson($series[$tokens[$i]], $series[$tokens[$j]]);
                if ($correlation === null) {
                    continue;
                }

                $sum += $correlation;
                $pairs++;
            }
        }

        return $pairs === 0 ? null : $sum / $pairs;
    }

    /**
     * @param  list<array<string, mixed>>  $bars
     * @return list<float>
     */
    private static function extractCloses(array $bars): array
    {
        return array_values(array_map(static fn (array $bar): float => (float) $bar['close'], $bars));
    }

    /**
     * Last `$window` log-returns from a close series.
     *
     * @param  list<float>  $closes
     * @return list<float>
     */
    private static function lastNReturns(array $closes, int $window): array
    {
        $count = count($closes);
        if ($count < $window) {
            return [];
        }

        $slice = array_slice($closes, -$window);
        $returns = [];
        for ($i = 1; $i < $window; $i++) {
            $prev = $slice[$i - 1];
            $cur = $slice[$i];
            if ($prev <= 0.0 || $cur <= 0.0) {
                $returns[] = 0.0;

                continue;
            }
            $returns[] = log($cur / $prev);
        }

        return $returns;
    }

    /**
     * Pearson correlation of two equal-length series. Returns null if
     * either series has zero variance (constant) and therefore cannot
     * contribute a meaningful pair.
     *
     * @param  list<float>  $x
     * @param  list<float>  $y
     */
    private static function pearson(array $x, array $y): ?float
    {
        $n = count($x);
        if ($n === 0 || $n !== count($y)) {
            return null;
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

        if ($varX <= 0.0 || $varY <= 0.0) {
            return null;
        }

        return $cov / sqrt($varX * $varY);
    }
}
