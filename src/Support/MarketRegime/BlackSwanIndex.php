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
 * Read-side façade over the kraite singleton's BSCS columns + the most
 * recent `market_regime_snapshots` row. Single static factory for
 * convenience, immutable instance values, lossless `toArray()` for
 * dashboard consumption.
 *
 * Two consumer types:
 *
 *   - `HasTradingGuards::canOpenPositions()` calls `shouldBlockOpens()`
 *     to decide whether the global opens-gate is closed.
 *   - Admin dashboard widget calls `toArray()` for the live regime
 *     panel (current score, band, cooldown remaining, sub-signal grid
 *     pulled from the latest snapshot, override status, freshness).
 *
 * Resolution rule for `shouldBlockOpens()`:
 *
 *   if  bscs_override_until > now()  → false  (operator escape hatch wins)
 *   elif bscs_cooldown_until > now() → true   (system cooldown active)
 *   else                              → false (no block)
 *
 * The override beats the cooldown so an operator can manually force
 * opens through during a system-set cooldown window if they decide the
 * regime is mis-reading the market — same shape as the spec's manual-
 * override clause but applied to the cooldown gate instead of the
 * legacy `bscs_block_active` flag.
 *
 * @see ~/docs/kraite/black-swan-logic.md
 */
final class BlackSwanIndex
{
    private function __construct(
        private readonly ?int $score,
        private readonly ?RegimeBand $band,
        private readonly ?CarbonImmutable $syncedAt,
        private readonly ?CarbonImmutable $cooldownUntil,
        private readonly ?CarbonImmutable $overrideUntil,
        private readonly int $blockThreshold,
        private readonly int $freshnessMaxSeconds,
        private readonly ?int $cooldownThreshold,
        private readonly ?int $cooldownHours,
        private readonly ?MarketRegimeSnapshot $latestSnapshot,
    ) {}

    /**
     * Build the index from the live kraite singleton + the most recent
     * snapshot row. Reads everything it needs in one place — callers
     * never need to know which column lives where.
     */
    public static function current(): self
    {
        $kraite = Kraite::find(1);
        $latest = MarketRegimeSnapshot::query()->orderByDesc('id')->first();
        $config = (array) (config('kraite.market_regime.cooldown') ?? []);

        return new self(
            score: $kraite?->bscs_score !== null ? (int) $kraite->bscs_score : null,
            band: $kraite?->bscs_band !== null ? RegimeBand::tryFrom((string) $kraite->bscs_band) : null,
            syncedAt: self::immutable($kraite?->bscs_synced_at),
            cooldownUntil: self::immutable($kraite?->bscs_cooldown_until),
            overrideUntil: self::immutable($kraite?->bscs_override_until),
            blockThreshold: (int) ($kraite?->bscs_block_threshold ?? 80),
            freshnessMaxSeconds: (int) ($kraite?->bscs_freshness_max_seconds ?? 6900),
            cooldownThreshold: isset($config['threshold']) ? (int) $config['threshold'] : null,
            cooldownHours: isset($config['hours']) ? (int) $config['hours'] : null,
            latestSnapshot: $latest,
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

    public function overrideUntil(): ?CarbonImmutable
    {
        return $this->overrideUntil;
    }

    public function isCooldownActive(): bool
    {
        return $this->cooldownUntil !== null && $this->cooldownUntil->isFuture();
    }

    public function isOverrideActive(): bool
    {
        return $this->overrideUntil !== null && $this->overrideUntil->isFuture();
    }

    /**
     * The system gate. Override beats cooldown so the operator escape
     * hatch always wins. No cooldown = open. Wired into
     * `HasTradingGuards::canOpenPositions()` as the BSCS check.
     */
    public function shouldBlockOpens(): bool
    {
        if ($this->isOverrideActive()) {
            return false;
        }

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
        return [
            'score' => $this->score,
            'band' => $this->band?->value,
            'synced_at' => $this->syncedAt?->toIso8601String(),
            'age_seconds' => $this->ageSeconds(),
            'is_stale' => $this->isStale(),
            'should_block_opens' => $this->shouldBlockOpens(),
            'cooldown_active' => $this->isCooldownActive(),
            'cooldown_until' => $this->cooldownUntil?->toIso8601String(),
            'override_active' => $this->isOverrideActive(),
            'override_until' => $this->overrideUntil?->toIso8601String(),
            'block_threshold' => $this->blockThreshold,
            'freshness_max_seconds' => $this->freshnessMaxSeconds,
            'cooldown_threshold' => $this->cooldownThreshold,
            'cooldown_hours' => $this->cooldownHours,
            'staleness' => $this->staleness()->value,
            'portfolio_risk' => $this->portfolioRisk()->toArray(),
            'sub_signals' => $this->latestSnapshot === null ? null : [
                'vol_expansion' => [
                    'value' => $this->latestSnapshot->vol_expansion_value,
                    'fired' => (bool) $this->latestSnapshot->vol_expansion_fired,
                ],
                'range_blowout' => [
                    'value' => $this->latestSnapshot->range_blowout_value,
                    'fired' => (bool) $this->latestSnapshot->range_blowout_fired,
                ],
                'corr_regime' => [
                    'value' => $this->latestSnapshot->corr_regime_value,
                    'fired' => (bool) $this->latestSnapshot->corr_regime_fired,
                ],
                'rejection_pct' => [
                    'value' => $this->latestSnapshot->rejection_pct_value,
                    'fired' => (bool) $this->latestSnapshot->rejection_pct_fired,
                ],
                'fut_vol' => [
                    'value' => $this->latestSnapshot->fut_vol_value,
                    'fired' => (bool) $this->latestSnapshot->fut_vol_fired,
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
