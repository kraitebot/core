<?php

declare(strict_types=1);

namespace Kraite\Core\Support\MarketRegime;

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Kraite\Core\Enums\BscsStaleness;
use Kraite\Core\Enums\RegimeBand;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\MarketRegimeSnapshot;

/**
 * Read-side state over the kraite singleton's BSCS columns. The most
 * recent `market_regime_snapshots` row is loaded lazily only when detailed
 * dashboard data requests it.
 *
 * Two consumer types:
 *
 *   - `HasTradingGuards::canOpenPositions()` calls `shouldBlockOpens()`
 *     to decide whether the global opens-gate is closed.
 *   - Admin dashboard widget calls `toArray()` for the live regime
 *     panel (current score, band, cooldown remaining, sub-signal grid
 *     pulled from the latest snapshot, freshness).
 *
 * Resolution rule for `shouldBlockOpens()`:
 *
 *   if  bscs_cooldown_until > now() → true   (system cooldown active)
 *   else                            → false  (no block)
 *
 * Critical is ABSOLUTE — the operator override (`bscs_override_until`)
 * was removed in Phase 3, so an armed cooldown can no longer be bypassed
 * by anyone. BSCS still computes hourly and the system reacts off the
 * coefficient automatically.
 *
 * @see ~/docs/kraite/black-swan-logic.md
 */
final class BscsState
{
    private bool $latestSnapshotLoaded = false;

    private ?MarketRegimeSnapshot $latestSnapshot = null;

    private function __construct(
        private readonly ?int $score,
        private readonly ?RegimeBand $band,
        private readonly ?CarbonImmutable $syncedAt,
        private readonly ?CarbonImmutable $cooldownUntil,
        private readonly int $blockThreshold,
        private readonly int $freshnessMaxSeconds,
        private readonly ?int $cooldownThreshold,
        private readonly ?int $cooldownHours,
    ) {}

    /**
     * Build the state from the live kraite singleton. Forensic snapshot
     * details remain lazy so trading decisions do not pay for them.
     */
    public static function current(): self
    {
        $kraite = Kraite::find(1);
        $marketRegimeConfig = (array) (config('kraite.market_regime') ?? []);
        $cooldownConfig = (array) ($marketRegimeConfig['cooldown'] ?? []);

        return new self(
            score: $kraite?->bscs_score !== null ? (int) $kraite->bscs_score : null,
            band: $kraite?->bscs_band !== null ? RegimeBand::tryFrom((string) $kraite->bscs_band) : null,
            syncedAt: self::immutable($kraite?->bscs_synced_at),
            cooldownUntil: self::immutable($kraite?->bscs_cooldown_until),
            blockThreshold: (int) ($kraite?->bscs_block_threshold
                ?? config()->integer('kraite.market_regime.block_threshold')),
            freshnessMaxSeconds: (int) ($kraite?->bscs_freshness_max_seconds
                ?? config()->integer('kraite.market_regime.freshness_max_seconds')),
            cooldownThreshold: isset($cooldownConfig['threshold']) ? (int) $cooldownConfig['threshold'] : null,
            // Runtime-settings pattern: the singleton column wins, NULL
            // inherits the config default. Governs the long score-cooldown
            // window only — the fast shock breaker keeps its own short pause.
            cooldownHours: (int) ($kraite?->bscs_cooldown_hours
                ?? $cooldownConfig['hours']
                ?? 12),
        );
    }

    public function score(): ?int
    {
        return $this->score;
    }

    public function band(): ?RegimeBand
    {
        return $this->band;
    }

    public function syncedAt(): ?CarbonImmutable
    {
        return $this->syncedAt;
    }

    public function cooldownUntil(): ?CarbonImmutable
    {
        return $this->cooldownUntil;
    }

    public function isCooldownActive(): bool
    {
        return $this->cooldownUntil !== null && $this->cooldownUntil->isFuture();
    }

    /**
     * The system gate. An armed cooldown blocks; no cooldown = open. There
     * is no operator override (removed Phase 3) — Critical is absolute.
     * Wired into `HasTradingGuards::canOpenPositions()` as the BSCS check.
     */
    public function shouldBlockOpens(): bool
    {
        // Stale-hard fails OPEN: the cooldown timestamp could be hours
        // out-of-date and we'd rather miss a pause than lock out the
        // autonomous bot on a broken signal. AnalyseBscsJob fires the
        // `market_regime_compute_stale` notification so the operator
        // notices.
        if ($this->staleness() === BscsStaleness::StaleHard) {
            return false;
        }

        return $this->isCooldownActive();
    }

    /**
     * Synced more than `freshness_max_seconds` ago — or never synced at
     * all. The analyse cron uses this to decide if it should fire the
     * `market_regime_compute_stale` notification.
     */
    public function isStale(): bool
    {
        return $this->staleness() !== BscsStaleness::Fresh;
    }

    /**
     * Three-tier freshness verdict. See `BscsStaleness` for semantics
     * and the spec section "Stale-soft mode" (Phase 2.1B) for the
     * gate / notification policy that consumes this.
     */
    public function staleness(): BscsStaleness
    {
        if ($this->syncedAt === null) {
            return BscsStaleness::StaleHard;
        }

        $now = CarbonImmutable::now();
        $ageSeconds = $now->getTimestamp() - $this->syncedAt->getTimestamp();

        if ($ageSeconds <= $this->freshnessMaxSeconds) {
            return BscsStaleness::Fresh;
        }

        $staleHardSeconds = (int) (config('kraite.market_regime.freshness.stale_hard_seconds') ?? 21600);
        if ($ageSeconds <= $staleHardSeconds) {
            return BscsStaleness::StaleSoft;
        }

        return BscsStaleness::StaleHard;
    }

    public function ageSeconds(): ?int
    {
        if ($this->syncedAt === null) {
            return null;
        }

        return (int) abs(CarbonImmutable::now()->diffInSeconds($this->syncedAt));
    }

    public function blockThreshold(): int
    {
        return $this->blockThreshold;
    }

    public function freshnessMaxSeconds(): int
    {
        return $this->freshnessMaxSeconds;
    }

    public function cooldownThreshold(): ?int
    {
        return $this->cooldownThreshold;
    }

    public function cooldownHours(): ?int
    {
        return $this->cooldownHours;
    }

    public function latestSnapshot(): ?MarketRegimeSnapshot
    {
        if (! $this->latestSnapshotLoaded) {
            $this->latestSnapshot = MarketRegimeSnapshot::query()->orderByDesc('id')->first();
            $this->latestSnapshotLoaded = true;
        }

        return $this->latestSnapshot;
    }

    /**
     * Portfolio shape — long/short counts, margin-at-risk per side,
     * largest-side ratio. Drives the dashboard "Book risk" line and
     * Phase 2.1C's directional crowding multiplier.
     *
     * Computed on demand (one query against `positions`); the index
     * doesn't memoise it because position state changes faster than
     * the BSCS cadence.
     */
    public function portfolioRisk(): DirectionalBookRisk
    {
        return DirectionalBookRisk::current();
    }

    /**
     * Lossless dashboard payload. Includes the singleton state, the
     * derived booleans, and the latest snapshot's sub-signal grid so
     * the admin widget can render score + 5-signal table in one call.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $latestSnapshot = $this->latestSnapshot();

        return [
            'score' => $this->score,
            'band' => $this->band?->value,
            'synced_at' => $this->syncedAt?->toIso8601String(),
            'age_seconds' => $this->ageSeconds(),
            'is_stale' => $this->isStale(),
            'should_block_opens' => $this->shouldBlockOpens(),
            'cooldown_active' => $this->isCooldownActive(),
            'cooldown_until' => $this->cooldownUntil?->toIso8601String(),
            'block_threshold' => $this->blockThreshold,
            'freshness_max_seconds' => $this->freshnessMaxSeconds,
            'cooldown_threshold' => $this->cooldownThreshold,
            'cooldown_hours' => $this->cooldownHours,
            'staleness' => $this->staleness()->value,
            'portfolio_risk' => $this->portfolioRisk()->toArray(),
            'sub_signals' => $latestSnapshot === null ? null : [
                'vol_expansion' => [
                    'value' => $latestSnapshot->vol_expansion_value,
                    'fired' => (bool) $latestSnapshot->vol_expansion_fired,
                ],
                'range_blowout' => [
                    'value' => $latestSnapshot->range_blowout_value,
                    'fired' => (bool) $latestSnapshot->range_blowout_fired,
                ],
                'corr_regime' => [
                    'value' => $latestSnapshot->corr_regime_value,
                    'fired' => (bool) $latestSnapshot->corr_regime_fired,
                ],
                'rejection_pct' => [
                    'value' => $latestSnapshot->rejection_pct_value,
                    'fired' => (bool) $latestSnapshot->rejection_pct_fired,
                ],
                'fut_vol' => [
                    'value' => $latestSnapshot->fut_vol_value,
                    'fired' => (bool) $latestSnapshot->fut_vol_fired,
                ],
            ],
        ];
    }

    private static function immutable(mixed $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof CarbonImmutable) {
            return $value;
        }

        if ($value instanceof Carbon) {
            return $value->toImmutable();
        }

        return CarbonImmutable::parse((string) $value);
    }
}
