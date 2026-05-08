<?php

declare(strict_types=1);

namespace Kraite\Core\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use StepDispatcher\Support\Steps;

/**
 * MaintenanceMode
 *
 * Lightweight, cache-backed maintenance flag for short windows where
 * the operator wants to gate cron entries off without restarting the
 * scheduler supervisor or shipping a full Laravel down() page.
 *
 * Per-prefix awareness (added 2026-05-08): the dispatcher now runs in
 * prefix-isolated pairs (default `steps_*` + `trading_steps_*`) so the
 * pause flag also speaks per prefix. Three pause scopes:
 *
 *   • `null`        — pauses BOTH dispatchers (backward compatible
 *     with the OPTIMIZE TABLE caller and any caller that wants a
 *     blanket pause). Stored under the all-scope key.
 *   • `''` (empty)  — pauses ONLY the default-prefix dispatcher.
 *   • `'trading'`   — pauses ONLY the trading-prefix dispatcher.
 *
 * `isStepsDispatchPaused($prefix)` returns true when either the
 * all-scope flag OR the per-prefix flag is set, so the all-scope
 * acts as a global override on top of any per-prefix gate.
 *
 * Storage: one Redis cache key per scope, each with its own TTL. The
 * TTL is a crash safety net — if the pause-process exits abnormally
 * before clearing the flag, dispatch auto-resumes after the TTL
 * expires instead of staying paused indefinitely. Callers should
 * still wrap pause/resume in a try/finally so the resume always runs.
 */
final class MaintenanceMode
{
    /**
     * Cache key gating EVERY `steps:dispatch` entry regardless of
     * prefix. Set by the no-arg / `$prefix=null` form of
     * `pauseStepsDispatch()`. The OPTIMIZE TABLE callers reach for
     * this scope so the entire dispatcher quiesces during a metadata
     * lock.
     */
    public const STEPS_DISPATCH_KEY = 'maintenance:steps-dispatch-paused';

    /**
     * Cache key prefix for per-prefix pause flags. The full key is
     * `STEPS_DISPATCH_KEY:{normalisedPrefix}` where the prefix is
     * the trailing-underscore form (`''` for default, `'trading_'`
     * for trading) so two different runtime prefixes never share
     * the same pause state.
     */
    public const STEPS_DISPATCH_PREFIX_KEY = 'maintenance:steps-dispatch-paused:prefix:';

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
        ?string $prefix = null,
    ): void {
        Cache::put(
            self::cacheKeyFor($prefix),
            [
                'reason' => $reason,
                'paused_at' => now()->toIso8601String(),
                'expires_in_seconds' => $ttlSeconds ?? self::DEFAULT_TTL_SECONDS,
                'prefix' => $prefix,
            ],
            $ttlSeconds ?? self::DEFAULT_TTL_SECONDS,
        );
    }

    public static function resumeStepsDispatch(?string $prefix = null): void
    {
        Cache::forget(self::cacheKeyFor($prefix));
    }

    /**
     * Is the dispatcher paused for the given prefix?
     *
     * Returns true when EITHER the all-scope flag is set OR the
     * per-prefix flag matching `$prefix` is set. The all-scope flag
     * acts as a blanket override so an OPTIMIZE TABLE caller using
     * the no-arg `pauseStepsDispatch()` form continues to gate every
     * dispatcher entry without having to enumerate prefixes.
     */
    public static function isStepsDispatchPaused(?string $prefix = null): bool
    {
        if (Cache::has(self::STEPS_DISPATCH_KEY)) {
            return true;
        }

        if ($prefix === null) {
            return false;
        }

        return Cache::has(self::cacheKeyFor($prefix));
    }

    /**
     * Inspect the current pause record for a given scope (reason +
     * paused_at + TTL). Returns null when the requested scope is not
     * paused. The all-scope and the per-prefix scope are inspected
     * separately — pass `null` to see the all-scope record.
     *
     * @return array{reason: string, paused_at: string, expires_in_seconds: int, prefix: ?string}|null
     */
    public static function stepsDispatchPauseInfo(?string $prefix = null): ?array
    {
        $payload = Cache::get(self::cacheKeyFor($prefix));

        if (! is_array($payload)) {
            return null;
        }

        return [
            'reason' => (string) ($payload['reason'] ?? '(unknown)'),
            'paused_at' => (string) ($payload['paused_at'] ?? ''),
            'expires_in_seconds' => (int) ($payload['expires_in_seconds'] ?? self::DEFAULT_TTL_SECONDS),
            'prefix' => $payload['prefix'] ?? $prefix,
        ];
    }

    /**
     * Convenience for callers wanting a relative human-readable label
     * (e.g. "paused 23s ago"). Returns null when the requested scope
     * is not paused.
     */
    public static function stepsDispatchPausedFor(?string $prefix = null): ?CarbonInterface
    {
        $info = self::stepsDispatchPauseInfo($prefix);

        if ($info === null || $info['paused_at'] === '') {
            return null;
        }

        return now()->parse($info['paused_at']);
    }

    /**
     * Resolve the cache key for a given scope. `null` returns the
     * all-scope key; any other value normalises the prefix to its
     * trailing-underscore form (so `'trading'` and `'trading_'`
     * collapse to the same key) and appends it after the per-prefix
     * key prefix.
     */
    private static function cacheKeyFor(?string $prefix): string
    {
        if ($prefix === null) {
            return self::STEPS_DISPATCH_KEY;
        }

        return self::STEPS_DISPATCH_PREFIX_KEY.Steps::normalise($prefix);
    }
}
