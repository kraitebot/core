<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Models\MarketRegime;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Enums\BscsStaleness;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Support\MarketRegime\Bscs;
use Kraite\Core\Support\MarketRegime\BscsState;
use Kraite\Core\Support\NotificationService;
use Throwable;

/**
 * AnalyseBscsJob — system-driven BSCS cooldown gate.
 *
 * Reads the latest score from the kraite singleton (populated by
 * `ComputeMarketRegimeJob`) and decides whether to arm, re-arm,
 * release, or no-op the system cooldown that blocks new opens.
 *
 * State machine:
 *
 *   1. score ≥ cooldown_threshold AND no cooldown active
 *      → arm: bscs_cooldown_until = now() + cooldown_hours,
 *        notify `market_regime_critical` once.
 *
 *   2. score ≥ cooldown_threshold AND cooldown expired in the past
 *      → re-arm: another cooldown_hours window.
 *
 *   3. score < cooldown_threshold AND cooldown expired in the past
 *      → release: notify `market_regime_recovered`, opens resume.
 *
 *   4. cooldown still in the future
 *      → no-op (already armed, nothing to do).
 *
 * @see BscsState
 * @see ~/docs/kraite/black-swan-logic.md
 */
final class AnalyseBscsJob extends BaseQueueableJob
{
    public function relatable(): ?Kraite
    {
        return Kraite::find(1);
    }

    /**
     * @return array{action: string, score: int|null, cooldown_until: string|null}
     */
    public function compute(): array
    {
        $index = Bscs::current();
        $kraite = Kraite::find(1);

        if ($kraite === null) {
            return $this->result('noop_no_kraite_row', null, null);
        }

        // Stale-hard: the compute pipeline has been silent for >6h.
        // Fire the operator-investigation notification (cache_duration
        // throttles repeat firings) and bail. The gate already
        // fail-opens via the BSCS opening policy so we
        // don't need to touch the cooldown column here.
        if ($index->staleness() === BscsStaleness::StaleHard) {
            $this->notifyComputeStale($index);

            return $this->result('noop_compute_stale', $index->score(), $index->cooldownUntil()?->toIso8601String());
        }

        $threshold = (int) (config('kraite.market_regime.cooldown.threshold', 80));
        // DB-override-first through the Bscs facade (kraite.bscs_cooldown_hours
        // wins, config default otherwise).
        $hours = (int) ($index->cooldownHours() ?? 12);
        $score = $index->score() ?? 0;
        $cooldownActive = $index->isCooldownActive();
        $hadCooldown = $index->cooldownUntil() !== null;

        // Future cooldown — already armed, leave it alone.
        if ($cooldownActive) {
            return $this->result('cooldown_already_active', $score, $index->cooldownUntil()?->toIso8601String());
        }

        if ($score >= $threshold) {
            $newCooldown = CarbonImmutable::now()->addHours($hours);
            $kraite->updateSaving([
                'bscs_cooldown_until' => $newCooldown,
                'bscs_block_active' => true,
            ]);

            $this->notifyCritical($score, $hours);

            return $this->result(
                $hadCooldown ? 'cooldown_rearmed' : 'cooldown_armed',
                $score,
                $newCooldown->toIso8601String(),
            );
        }

        // Score below threshold. If a cooldown was just expiring, mark
        // recovered. The cooldown timestamp is cleared so subsequent ticks
        // recognise the state as "no cooldown" and do NOT re-enter this
        // branch — without the null, every tick after the first recovery
        // re-fired the notification (Pos #577 sibling incident, 2026-05-06).
        if ($hadCooldown) {
            $kraite->updateSaving([
                'bscs_block_active' => false,
                'bscs_cooldown_until' => null,
            ]);

            $this->notifyRecovered($score);

            return $this->result('cooldown_released', $score, null);
        }

        return $this->result('noop_below_threshold', $score, null);
    }

    /**
     * @return array{action: string, score: int|null, cooldown_until: string|null}
     */
    private function result(string $action, ?int $score, ?string $cooldownUntil): array
    {
        return [
            'action' => $action,
            'score' => $score,
            'cooldown_until' => $cooldownUntil,
        ];
    }

    private function notifyCritical(int $score, int $hours): void
    {
        try {
            NotificationService::send(
                user: Kraite::admin(),
                canonical: 'market_regime_critical',
                referenceData: [
                    'score' => $score,
                    'cooldown_hours' => $hours,
                ],
            );
        } catch (Throwable $e) {
            Log::warning('[AnalyseBscsJob] critical notification dispatch failed', [
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function notifyComputeStale(BscsState $index): void
    {
        try {
            $age = $index->ageSeconds();
            $hours = $age === null ? null : (int) round($age / 3600);

            NotificationService::send(
                user: Kraite::admin(),
                canonical: 'market_regime_compute_stale',
                referenceData: [
                    'stale_hours' => $hours ?? 0,
                ],
            );
        } catch (Throwable $e) {
            Log::warning('[AnalyseBscsJob] compute_stale notification dispatch failed', [
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function notifyRecovered(int $score): void
    {
        try {
            NotificationService::send(
                user: Kraite::admin(),
                canonical: 'market_regime_recovered',
                referenceData: [
                    'score' => $score,
                ],
            );
        } catch (Throwable $e) {
            Log::warning('[AnalyseBscsJob] recovered notification dispatch failed', [
                'message' => $e->getMessage(),
            ]);
        }
    }
}
