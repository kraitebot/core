<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Models\MarketRegime;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Enums\BscsScoreTransition;
use Kraite\Core\Enums\RegimeBand;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\Candle;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\MarketRegimeSnapshot;
use Kraite\Core\Support\MarketRegime\RegimeCalculator;
use Kraite\Core\Support\TraderAppNotificationService;
use Throwable;

/**
 * ComputeMarketRegimeJob — Phase 1 (read-only telemetry).
 *
 * Hourly job that computes the Black Swan Composite Score (BSCS) from
 * 1h klines on BTC + 4 reference alts (ETH/SOL/BNB/XRP), persists a
 * snapshot, and denormalises the latest score onto the `kraite`
 * singleton. Phase 1 does NOT touch trading flow — `bscs_block_active`
 * is set per the band logic but `HasTradingGuards` doesn't read it
 * yet.
 *
 * @see ~/docs/kraite/black-swan-logic.md
 * @see RegimeCalculator
 */
final class ComputeMarketRegimeJob extends BaseQueueableJob
{
    /** @var list<string> */
    private array $altTokens = ['ETH', 'SOL', 'BNB', 'XRP'];

    private string $btcToken = 'BTC';

    private string $quote = 'USDT';

    public function relatable(): ?Kraite
    {
        return Kraite::find(1);
    }

    public function compute(): array
    {
        $config = config('kraite.market_regime');
        $symbols = $this->resolveSymbols((array) ($config['symbols'] ?? []));
        $thresholds = $this->resolveThresholds((array) ($config['thresholds'] ?? []));

        $floorConfig = (array) ($config['drawdown_floor'] ?? []);
        $floorWindow = (int) ($floorConfig['window_hours'] ?? 500);
        $btcBars = $this->loadKlines($symbols['BTC'] ?? null, max(360, $floorWindow + 1));
        if (count($btcBars) < 14 * 24) {
            // Not enough history to compute meaningfully — bail without
            // writing a snapshot. The cron will retry next hour; the
            // freshness gate (Phase 2) handles staleness on its own.
            Log::warning('[ComputeMarketRegimeJob] insufficient BTC kline history, skipping', [
                'bars_loaded' => count($btcBars),
                'bars_required' => 14 * 24,
            ]);

            return [
                'computed' => false,
                'reason' => 'insufficient_btc_history',
            ];
        }

        $altBars = [];
        foreach ($this->altTokens as $token) {
            $altBars[$token] = $this->loadKlines($symbols[$token] ?? null);
        }

        $values = RegimeCalculator::computeSubSignalValues($btcBars, $altBars);
        $fired = RegimeCalculator::applyThresholds($values, $thresholds);
        $score = RegimeCalculator::compositeScore($fired);

        // Drawdown floor — absolute-state overlay for continuation
        // regimes the relative sub-signals cannot see. Floors the score
        // (never lowers it, never reaches Critical on its own).
        $drawdown = [
            'enabled' => (bool) ($floorConfig['enabled'] ?? true),
            'window_hours' => $floorWindow,
            'threshold_pct' => (float) ($floorConfig['threshold_pct'] ?? 15.0),
            'floor_score' => (int) ($floorConfig['floor_score'] ?? 60),
            'value_pct' => null,
            'floor_applied' => false,
        ];

        if ($drawdown['enabled']) {
            $drawdownPct = RegimeCalculator::drawdownPct($btcBars, $floorWindow);
            $drawdown['value_pct'] = $drawdownPct === null ? null : round($drawdownPct, 2);

            if ($drawdownPct !== null
                && $drawdownPct >= $drawdown['threshold_pct']
                && $score < $drawdown['floor_score']
            ) {
                $score = $drawdown['floor_score'];
                $drawdown['floor_applied'] = true;
            }
        }

        $band = RegimeBand::fromScore($score);

        $btcLastClose = (string) end($btcBars)['close'];
        $computedAt = CarbonImmutable::now()->startOfHour();

        $snapshot = MarketRegimeSnapshot::create([
            'computed_at' => $computedAt,
            'bscs_score' => $score,
            'bscs_band' => $band->value,
            'vol_expansion_value' => (string) round($values['vol_expansion'], 4),
            'vol_expansion_fired' => $fired['vol_expansion_fired'],
            'range_blowout_value' => (string) round($values['range_blowout'], 4),
            'range_blowout_fired' => $fired['range_blowout_fired'],
            'corr_regime_value' => (string) round($values['corr_regime'], 4),
            'corr_regime_fired' => $fired['corr_regime_fired'],
            'rejection_pct_value' => (string) round($values['rejection_pct'], 2),
            'rejection_pct_fired' => $fired['rejection_pct_fired'],
            'fut_vol_value' => (string) round($values['fut_vol'], 4),
            'fut_vol_fired' => $fired['fut_vol_fired'],
            'btc_close' => $btcLastClose,
            'inputs_meta' => [
                'symbols' => array_keys($symbols),
                'baseline_days' => 14,
                'thresholds' => $thresholds,
                'drawdown_floor' => $drawdown,
            ],
        ]);

        $this->updateKraiteSingleton($score, $band, $computedAt);

        try {
            Kraite::find(1)?->modelLog('bscs_recompute', [
                'score' => $score,
                'band' => $band->value,
                'snapshot_id' => $snapshot->id,
            ]);
        } catch (Throwable $e) {
            Log::warning('[ComputeMarketRegimeJob] modelLog failed', ['message' => $e->getMessage()]);
        }

        return [
            'computed' => true,
            'snapshot_id' => $snapshot->id,
            'score' => $score,
            'band' => $band->value,
            'drawdown_floor' => $drawdown,
        ];
    }

    /**
     * Map token → ExchangeSymbol on the binance api system. Returns
     * `[token => exchangeSymbol]`. Missing tokens are silently absent;
     * the calculator handles short ALT data by skipping that series.
     *
     * @param  list<string>  $configuredSymbols  e.g. ['BTCUSDT', ...]
     * @return array<string, ExchangeSymbol>
     */
    private function resolveSymbols(array $configuredSymbols): array
    {
        $tokens = array_unique(array_merge([$this->btcToken], $this->altTokens));
        $binance = ApiSystem::canonical('binance')->first();
        if ($binance === null) {
            return [];
        }

        $rows = ExchangeSymbol::query()
            ->whereIn('token', $tokens)
            ->where('quote', $this->quote)
            ->where('api_system_id', $binance->id)
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[$row->token] = $row;
        }

        return $out;
    }

    /**
     * @return list<array{open: string, high: string, low: string, close: string, volume: string, timestamp: int}>
     */
    private function loadKlines(?ExchangeSymbol $exchangeSymbol, int $limit = 360): array
    {
        if ($exchangeSymbol === null) {
            return [];
        }

        // 14 days = 336 hourly bars; pull 360 to be defensive. BTC pulls
        // more when the drawdown floor needs its longer window.
        $rows = Candle::query()
            ->where('exchange_symbol_id', $exchangeSymbol->id)
            ->where('timeframe', '1h')
            ->orderByDesc('timestamp')
            ->limit($limit)
            ->get(['open', 'high', 'low', 'close', 'volume', 'timestamp']);

        // DB returned newest-first; calculator expects oldest-first.
        return $rows->reverse()->values()->map(static fn ($row) => [
            'open' => (string) $row->open,
            'high' => (string) $row->high,
            'low' => (string) $row->low,
            'close' => (string) $row->close,
            'volume' => (string) $row->volume,
            'timestamp' => (int) $row->timestamp,
        ])->all();
    }

    /**
     * @param  array<string, float|int|string>  $configThresholds
     * @return array{vol_expansion: float, range_blowout: float, corr_regime: float, rejection_pct: float, fut_vol: float}
     */
    private function resolveThresholds(array $configThresholds): array
    {
        $defaults = RegimeCalculator::defaultThresholds();
        $resolved = $defaults;
        foreach ($defaults as $key => $default) {
            if (array_key_exists($key, $configThresholds)) {
                $resolved[$key] = (float) $configThresholds[$key];
            }
        }

        /** @var array{vol_expansion: float, range_blowout: float, corr_regime: float, rejection_pct: float, fut_vol: float} $resolved */
        return $resolved;
    }

    private function updateKraiteSingleton(int $score, RegimeBand $band, CarbonImmutable $computedAt): void
    {
        $kraite = Kraite::find(1);
        if ($kraite === null) {
            return;
        }

        $previousScore = $kraite->bscs_score === null ? null : (int) $kraite->bscs_score;

        // Phase 1 invariant: bscs_block_active stays FALSE.
        //
        // The 4-week observation window per spec requires the gate to be
        // observed but never enforced — operators read the dashboard, eyeball
        // the score against actual market regime, and tune thresholds before
        // any flag flips trading flow off. Open-blocking is now driven by
        // the bscs_cooldown_until cooldown (armed by AnalyseBscsJob) and
        // read by HasTradingGuards::canOpenPositions(); there is no
        // operator override (removed Phase 3).
        $kraite->updateSaving([
            'bscs_score' => $score,
            'bscs_band' => $band->value,
            'bscs_synced_at' => $computedAt,
            'bscs_block_active' => false,
        ]);

        $transition = BscsScoreTransition::detect($previousScore, $score);
        if ($transition === null) {
            return;
        }

        TraderAppNotificationService::send(
            canonical: 'market_regime_score_changed',
            referenceData: [
                'previous_score' => $previousScore,
                'score' => $score,
                'transition' => $transition->value,
            ],
            relatable: $kraite,
        );
    }
}
