<?php

declare(strict_types=1);

namespace Kraite\Core\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

/**
 * MaintenanceMode
 *
 * Lightweight, cache-backed maintenance flag for short windows where
 * the operator wants to gate cron entries off without restarting the
 * scheduler supervisor or shipping a full Laravel down() page.
 *
 * Current consumer: `OptimizeBreadcrumbTablesCommand`. The nightly
 * `OPTIMIZE TABLE` run takes brief metadata locks at the end of each
 * table rebuild; pausing `steps:dispatch` for the duration prevents
 * the dispatcher from picking up Pending → Dispatched mid-rebuild and
 * blocking on the lock. Once optimisation finishes, the flag is
 * cleared and dispatch resumes on the very next cron tick.
 *
 * Storage: a Redis cache key (one per gated subsystem) with a TTL.
 * The TTL is a crash safety net — if the optimise command exits
 * abnormally before clearing the flag, dispatch auto-resumes after
 * the TTL expires instead of staying paused indefinitely. Callers
 * should still wrap pause/resume in a try/finally so the resume
 * always runs.
 */
final class MaintenanceMode
{
    /**
     * Cache key gating `steps:dispatch`. The ingestion scheduler
     * invokes `MaintenanceMode::isStepsDispatchPaused()` from a
     * `->skip()` callback on the `steps:dispatch` schedule entry.
     */
    public const STEPS_DISPATCH_KEY = 'maintenance:steps-dispatch-paused';

    /**
     * Default safety-net TTL applied to every pause if the caller
     * doesn't supply one. 30 minutes is well over the worst observed
     * wall-clock for the parallel OPTIMIZE pass (~140s for the
     * largest table) yet short enough that an orphaned flag self-
     * heals on the same shift.
     */
    public const DEFAULT_TTL_SECONDS = 1800;

    public static function pauseStepsDispatch(
        string $reason,
        ?int $ttlSeconds = null,
    ): void {
        Cache::put(
            self::STEPS_DISPATCH_KEY,
            [
                'reason' => $reason,
                'paused_at' => now()->toIso8601String(),
                'expires_in_seconds' => $ttlSeconds ?? self::DEFAULT_TTL_SECONDS,
            ],
            $ttlSeconds ?? self::DEFAULT_TTL_SECONDS,
        );
    }

    public static function resumeStepsDispatch(): void
    {
        Cache::forget(self::STEPS_DISPATCH_KEY);
    }

    public static function isStepsDispatchPaused(): bool
    {
        return Cache::has(self::STEPS_DISPATCH_KEY);
    }

    /**
     * Inspect the current pause record (reason + paused_at + TTL).
     * Returns null when dispatch is not paused.
     *
     * @return array{reason: string, paused_at: string, expires_in_seconds: int}|null
     */
    public static function stepsDispatchPauseInfo(): ?array
    {
        $payload = Cache::get(self::STEPS_DISPATCH_KEY);

        if (! is_array($payload)) {
            return null;
        }

        return [
            'reason' => (string) ($payload['reason'] ?? '(unknown)'),
            'paused_at' => (string) ($payload['paused_at'] ?? ''),
            'expires_in_seconds' => (int) ($payload['expires_in_seconds'] ?? self::DEFAULT_TTL_SECONDS),
        ];
    }

    /**
     * Convenience for callers wanting a relative human-readable label
     * (e.g. "paused 23s ago"). Returns null when not paused.
     */
    public static function stepsDispatchPausedFor(): ?CarbonInterface
    {
        $info = self::stepsDispatchPauseInfo();

        if ($info === null || $info['paused_at'] === '') {
            return null;
        }

        return now()->parse($info['paused_at']);
    }
}
