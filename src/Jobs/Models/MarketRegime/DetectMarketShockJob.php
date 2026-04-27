<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Models\MarketRegime;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\Candle;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Support\MarketRegime\BlackSwanIndex;
use Kraite\Core\Support\MarketRegime\MarketShockCircuitBreaker;
use Kraite\Core\Support\NotificationService;
use Throwable;

/**
 * Fast cascade-in-progress detector. Reads the latest 15m klines for
 * BTC + the 4 BSCS reference alts (ETH/SOL/BNB/XRP), runs the
 * MarketShockCircuitBreaker rules, and arms the SHARED
 * `bscs_cooldown_until` column when any rule fires.
 *
 * Cadence: 1 minute (cron `kraite:cron-detect-market-shock`).
 *
 * State machine:
 *
 *   - operator override active   → noop_override_active
 *   - cooldown already active    → cooldown_already_active (silent;
 *                                  no re-arm, no duplicate notification —
 *                                  cascade signal will keep firing every
 *                                  minute and we don't want to spam)
 *   - rule fires + clean state   → cooldown_armed + Pushover notification
 *   - no rule fires              → noop_no_fire
 *   - reference klines missing   → noop_insufficient_data
 *
 * The 15m klines are kept fresh by `kraite:cron-fetch-klines
 * --reference-set --canonical=binance --timeframe=15m` running every
 * 15 minutes. With a 1-minute detector cadence, the WORST-case latency
 * between a cliff starting and the gate arming is `<bar_close_lag> +
 * <fetch_cron_phase> + 60s` — around 4-15 minutes vs the 50 minutes
 * the hourly BSCS compute would have taken.
 *
 * @see ~/docs/kraite/black-swan-logic.md (Phase 2.1A)
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

    public function relatable(): ?Kraite
    {
        return Kraite::find(1);
    }

    /**
     * @return array{action: string, rules_triggered?: list<string>, btc_15m_pct?: float, btc_1h_pct?: float}
     */
    public function compute(): array
    {
        $index = BlackSwanIndex::current();

        if ($index->isOverrideActive()) {
            return ['action' => 'noop_override_active'];
        }

        $btcBars = $this->loadBars('BTC');
        if ($btcBars === null) {
            return ['action' => 'noop_insufficient_data'];
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
                'btc_15m_pct' => $result['btc_15m_pct'],
                'btc_1h_pct' => $result['btc_1h_pct'],
            ];
        }

        // Cooldown already armed (by BSCS or a previous shock fire).
        // Silent no-op — cascade detector keeps firing minute after
        // minute as the move continues, and we don't want to spam
        // Pushover or fight the existing cooldown timestamp.
        if ($index->isCooldownActive()) {
            return [
                'action' => 'cooldown_already_active',
                'rules_triggered' => $result['rules_triggered'],
            ];
        }

        $hours = (int) (config('kraite.market_regime.shock.cooldown_hours', 24));
        $newCooldown = CarbonImmutable::now()->addHours($hours);

        $kraite = Kraite::find(1);
        $kraite->updateSaving([
            'bscs_cooldown_until' => $newCooldown,
            'bscs_block_active' => true,
        ]);

        $this->notifyShock($result, $hours);

        return [
            'action' => 'cooldown_armed',
            'rules_triggered' => $result['rules_triggered'],
            'btc_15m_pct' => $result['btc_15m_pct'],
            'btc_1h_pct' => $result['btc_1h_pct'],
        ];
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
    private function notifyShock(array $result, int $hours): void
    {
        try {
            NotificationService::send(
                user: Kraite::admin(),
                canonical: 'market_shock_circuit_breaker',
                referenceData: [
                    'rules' => implode(', ', $result['rules_triggered']),
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
