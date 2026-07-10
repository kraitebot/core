<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Models\MarketRegime;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\Candle;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\MarketPriceSample;
use Kraite\Core\Support\MarketRegime\BlackSwanIndex;
use Kraite\Core\Support\MarketRegime\MarketShockCircuitBreaker;
use Kraite\Core\Support\NotificationService;
use Throwable;

/**
 * Fast cascade-in-progress detector, two evaluation paths:
 *
 * LIVE WINDOW (primary since core 1.63.0): every tick samples the
 * reference basket's `exchange_symbols.mark_price` (1s-fresh via the
 * price WS daemon) into `market_price_samples`, then evaluates the
 * breaker rules on rolling 1-minute windows (offsets 15/60, corr 61).
 * Arms only after `live_window.persistence_ticks` consecutive breaching
 * ticks (default 2 — kills single-minute wicks; the replay showed 3
 * costs real early catches). Worst-case cascade reaction: ~1-2 minutes.
 * Replay-validated across the 6 historical events —
 * ~/blackswan/reports/fast-breaker-replay-20260711.txt.
 *
 * KLINE FALLBACK (the original path, unchanged): 15m klines refreshed
 * every 15 min by the reference-set cron; fires immediately, no
 * persistence. Covers price-daemon outages, thin sample buffers
 * (first hour after deploy), and the live path's kill switch
 * (`MARKET_SHOCK_LIVE_WINDOW=false` reverts to pre-live behaviour).
 *
 * Both paths arm the SHARED `bscs_cooldown_until` column, which blocks
 * new opens via `HasTradingGuards::canOpenPositions()`. Existing
 * positions are never touched.
 *
 * Cadence: 1 minute (cron `kraite:cron-detect-market-shock`).
 *
 * State machine:
 *
 *   - cooldown already active    → cooldown_already_active (silent)
 *   - live path breach streak    → cache run counter; reaches
 *     persistence_ticks          → cooldown_armed (source=live_window)
 *   - kline rule fires           → cooldown_armed (source=kline)
 *   - no rule fires              → noop_no_fire (live run counter reset)
 *   - reference klines missing   → noop_insufficient_data
 *
 * @see ~/docs/kraite/black-swan-logic.md (Phase 2.1A + live window)
 */
final class DetectMarketShockJob extends BaseQueueableJob
{
    private const REFERENCE_TOKENS = ['BTC', 'ETH', 'SOL', 'BNB', 'XRP'];

    private const QUOTE = 'USDT';

    private const TIMEFRAME = '15m';

    /**
     * Need at least 12 bars (correlation window) + 4-back lookup margin.
     * Pull 20 to be defensive without hammering the DB.
     */
    private const BAR_FETCH_LIMIT = 20;

    /**
     * Live path: 61 one-minute samples = a 60-minute rolling window
     * carrying one hour of 1m returns for the correlation rule.
     */
    private const LIVE_SERIES_LENGTH = 61;

    /**
     * Max seconds between consecutive samples before the series counts
     * as gapped (dispatcher hiccup, skipped tick) — the live path then
     * skips the tick rather than evaluating a distorted window.
     */
    private const LIVE_MAX_GAP_SECONDS = 180;

    private const LIVE_RETENTION_HOURS = 3;

    private const LIVE_BREACH_CACHE_KEY = 'market_shock_live_breach_run';

    public function relatable(): ?Kraite
    {
        return Kraite::find(1);
    }

    /**
     * @return array{action: string, live_status?: string, source?: string, rules_triggered?: list<string>, btc_15m_pct?: float, btc_1h_pct?: float}
     */
    public function compute(): array
    {
        $index = BlackSwanIndex::current();
        $liveStatus = 'disabled';

        if ($this->liveWindowEnabled()) {
            $this->sampleLiveMarks();
            $this->pruneLiveSamples();

            $liveOutcome = $this->evaluateLiveWindow($index);
            $liveStatus = $liveOutcome['status'];

            if ($liveOutcome['armed']) {
                return [
                    'action' => 'cooldown_armed',
                    'live_status' => $liveStatus,
                    'source' => 'live_window',
                    'rules_triggered' => $liveOutcome['result']['rules_triggered'],
                    'btc_15m_pct' => $liveOutcome['result']['btc_15m_pct'],
                    'btc_1h_pct' => $liveOutcome['result']['btc_1h_pct'],
                ];
            }
        }

        // ---- Kline fallback path (original behaviour, unchanged) ----

        $btcBars = $this->loadBars('BTC');
        if ($btcBars === null) {
            return ['action' => 'noop_insufficient_data', 'live_status' => $liveStatus];
        }

        $altBars = [];
        foreach (['ETH', 'SOL', 'BNB', 'XRP'] as $token) {
            $bars = $this->loadBars($token);
            if ($bars !== null) {
                $altBars[$token] = $bars;
            }
        }

        $thresholds = $this->resolveThresholds();
        $result = MarketShockCircuitBreaker::evaluate($btcBars, $altBars, $thresholds);

        if (! $result['fired']) {
            return [
                'action' => 'noop_no_fire',
                'live_status' => $liveStatus,
                'btc_15m_pct' => $result['btc_15m_pct'],
                'btc_1h_pct' => $result['btc_1h_pct'],
            ];
        }

        // Cooldown already armed (by BSCS, the live path, or a previous
        // shock fire). Silent no-op — the cascade signal keeps firing
        // minute after minute and we don't want to spam Pushover or
        // fight the existing cooldown timestamp.
        if ($index->isCooldownActive()) {
            return [
                'action' => 'cooldown_already_active',
                'live_status' => $liveStatus,
                'rules_triggered' => $result['rules_triggered'],
            ];
        }

        $this->armCooldown($result, 'kline');

        return [
            'action' => 'cooldown_armed',
            'live_status' => $liveStatus,
            'source' => 'kline',
            'rules_triggered' => $result['rules_triggered'],
            'btc_15m_pct' => $result['btc_15m_pct'],
            'btc_1h_pct' => $result['btc_1h_pct'],
        ];
    }

    // ------------------------------------------------------------------
    // Live-window path
    // ------------------------------------------------------------------

    public function liveWindowEnabled(): bool
    {
        return (bool) config('kraite.market_regime.shock.live_window.enabled', true);
    }

    /**
     * Copy the current mark price of every reference token into the
     * rolling sample buffer. Tokens whose mark is stale (price daemon
     * degraded) are skipped — a gapped series fails the gap guard and
     * the kline path covers the tick.
     */
    public function sampleLiveMarks(): void
    {
        $maxAge = (int) config('kraite.market_regime.shock.live_window.mark_max_age_seconds', 90);
        $binance = ApiSystem::where('canonical', 'binance')->first();

        if ($binance === null) {
            return;
        }

        $symbols = ExchangeSymbol::query()
            ->where('api_system_id', $binance->id)
            ->where('quote', self::QUOTE)
            ->whereIn('token', self::REFERENCE_TOKENS)
            ->get();

        foreach ($symbols as $symbol) {
            if ($symbol->mark_price === null || $symbol->mark_price_synced_at === null) {
                continue;
            }

            if ($symbol->mark_price_synced_at->lt(now()->subSeconds($maxAge))) {
                continue;
            }

            MarketPriceSample::create([
                'token' => $symbol->token,
                'price' => $symbol->mark_price,
                'sampled_at' => now(),
            ]);
        }
    }

    /**
     * Rolling-buffer trim. Query-builder delete is deliberate: the
     * samples table is a detection buffer with no observers and no
     * business meaning — bulk delete is the correct tool here.
     */
    public function pruneLiveSamples(): void
    {
        MarketPriceSample::query()
            ->where('sampled_at', '<', now()->subHours(self::LIVE_RETENTION_HOURS))
            ->delete();
    }

    /**
     * Evaluate the breaker on the rolling 1-minute series with the
     * consecutive-tick persistence guard.
     *
     * @return array{status: string, armed: bool, result: array<string, mixed>}
     */
    public function evaluateLiveWindow(BlackSwanIndex $index): array
    {
        $btcSeries = $this->loadLiveSeries('BTC');

        if ($btcSeries === null) {
            Cache::forget(self::LIVE_BREACH_CACHE_KEY);

            return ['status' => 'insufficient_series', 'armed' => false, 'result' => []];
        }

        $altSeries = [];
        foreach (['ETH', 'SOL', 'BNB', 'XRP'] as $token) {
            $series = $this->loadLiveSeries($token);
            if ($series !== null) {
                $altSeries[$token] = $series;
            }
        }

        $result = MarketShockCircuitBreaker::evaluate(
            $btcSeries,
            $altSeries,
            $this->resolveThresholds(),
            shortOffset: 15,
            longOffset: 60,
            corrWindow: self::LIVE_SERIES_LENGTH,
        );

        if (! $result['fired']) {
            Cache::forget(self::LIVE_BREACH_CACHE_KEY);

            return ['status' => 'no_fire', 'armed' => false, 'result' => $result];
        }

        $run = (int) Cache::get(self::LIVE_BREACH_CACHE_KEY, 0) + 1;
        Cache::put(self::LIVE_BREACH_CACHE_KEY, $run, now()->addMinutes(10));

        $needed = (int) config('kraite.market_regime.shock.live_window.persistence_ticks', 2);

        if ($run < $needed) {
            return ['status' => "breach_pending_{$run}_of_{$needed}", 'armed' => false, 'result' => $result];
        }

        if ($index->isCooldownActive()) {
            return ['status' => 'breach_confirmed_cooldown_already_active', 'armed' => false, 'result' => $result];
        }

        $this->armCooldown($result, 'live_window');

        return ['status' => 'breach_confirmed_armed', 'armed' => true, 'result' => $result];
    }

    /**
     * Load the newest LIVE_SERIES_LENGTH samples for a token as
     * newest-LAST bars. Returns null when the series is short, spans
     * the wrong wall-clock width, or contains a gap — any of which
     * would distort the rolling windows.
     *
     * @return list<array{close: string, timestamp: int}>|null
     */
    public function loadLiveSeries(string $token): ?array
    {
        $samples = MarketPriceSample::query()
            ->where('token', $token)
            ->orderByDesc('sampled_at')
            ->limit(self::LIVE_SERIES_LENGTH)
            ->get(['price', 'sampled_at'])
            ->reverse()
            ->values();

        if ($samples->count() < self::LIVE_SERIES_LENGTH) {
            return null;
        }

        $spanSeconds = (int) abs($samples->last()->sampled_at->diffInSeconds($samples->first()->sampled_at));
        $expected = (self::LIVE_SERIES_LENGTH - 1) * 60;
        if ($spanSeconds < $expected - 120 || $spanSeconds > $expected + 240) {
            return null;
        }

        $previous = null;
        foreach ($samples as $sample) {
            if ($previous !== null
                && abs($sample->sampled_at->diffInSeconds($previous->sampled_at)) > self::LIVE_MAX_GAP_SECONDS) {
                return null;
            }
            $previous = $sample;
        }

        return $samples->map(static function ($sample) {
            return [
                'close' => (string) $sample->price,
                'timestamp' => $sample->sampled_at->getTimestamp(),
            ];
        })->all();
    }

    // ------------------------------------------------------------------
    // Shared arming + kline path helpers
    // ------------------------------------------------------------------

    /**
     * @param  array{rules_triggered: list<string>, btc_15m_pct: float, btc_1h_pct: float, alt_basket_1h_pct: float, corr_1h: float}  $result
     */
    public function armCooldown(array $result, string $source): void
    {
        $hours = (int) (config('kraite.market_regime.shock.cooldown_hours', 24));

        $kraite = Kraite::find(1);
        $kraite->updateSaving([
            'bscs_cooldown_until' => CarbonImmutable::now()->addHours($hours),
            'bscs_block_active' => true,
        ]);

        $this->notifyShock($result, $hours, $source);
    }

    /**
     * Load the latest BAR_FETCH_LIMIT × 15m bars for a token on Binance.
     * Returns null when fewer than the calculator's minimum window is
     * available (calculator handles the rest of the short-data
     * defensiveness internally).
     *
     * @return list<array{close: string, timestamp: int}>|null
     */
    private function loadBars(string $token): ?array
    {
        $binance = ApiSystem::where('canonical', 'binance')->first();
        if ($binance === null) {
            return null;
        }

        $exchangeSymbol = ExchangeSymbol::query()
            ->where('api_system_id', $binance->id)
            ->where('token', $token)
            ->where('quote', self::QUOTE)
            ->first();

        if ($exchangeSymbol === null) {
            return null;
        }

        $rows = Candle::query()
            ->where('exchange_symbol_id', $exchangeSymbol->id)
            ->where('timeframe', self::TIMEFRAME)
            ->orderByDesc('timestamp')
            ->limit(self::BAR_FETCH_LIMIT)
            ->get(['close', 'timestamp']);

        if ($rows->count() < 5) {
            return null;
        }

        // DB returned newest-first; calculator expects newest-LAST.
        return $rows->reverse()->values()->map(static fn ($row) => [
            'close' => (string) $row->close,
            'timestamp' => (int) $row->timestamp,
        ])->all();
    }

    /**
     * @return array{btc_15m_pct: float, btc_1h_pct: float, alt_basket_1h_pct: float, corr_1h: float, magnitude_pct: float}
     */
    private function resolveThresholds(): array
    {
        $configured = (array) (config('kraite.market_regime.shock.thresholds') ?? []);
        $defaults = MarketShockCircuitBreaker::defaultThresholds();
        $resolved = $defaults;
        foreach ($defaults as $key => $default) {
            if (array_key_exists($key, $configured)) {
                $resolved[$key] = (float) $configured[$key];
            }
        }

        /** @var array{btc_15m_pct: float, btc_1h_pct: float, alt_basket_1h_pct: float, corr_1h: float, magnitude_pct: float} $resolved */
        return $resolved;
    }

    /**
     * @param  array{rules_triggered: list<string>, btc_15m_pct: float, btc_1h_pct: float, alt_basket_1h_pct: float, corr_1h: float}  $result
     */
    private function notifyShock(array $result, int $hours, string $source): void
    {
        try {
            NotificationService::send(
                user: Kraite::admin(),
                canonical: 'market_shock_circuit_breaker',
                referenceData: [
                    'rules' => implode(', ', $result['rules_triggered']),
                    'source' => $source,
                    'btc_15m_pct' => round($result['btc_15m_pct'], 2),
                    'btc_1h_pct' => round($result['btc_1h_pct'], 2),
                    'alt_basket_1h_pct' => round($result['alt_basket_1h_pct'], 2),
                    'corr_1h' => round($result['corr_1h'], 3),
                    'cooldown_hours' => $hours,
                ],
            );
        } catch (Throwable $e) {
            Log::warning('[DetectMarketShockJob] notification dispatch failed', [
                'message' => $e->getMessage(),
            ]);
        }
    }
}
