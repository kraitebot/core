<?php

declare(strict_types=1);

namespace Kraite\Core\Abstracts;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;


/**
 * BaseApiThrottler
 *
 * Abstract class for API rate limiting/throttling.
 * Provides a reusable mechanism to enforce rate limits for external APIs.
 * Uses Cache to track request timestamps and counts.
 *
 * Subclasses must implement getRateLimitConfig() to define API-specific limits.
 */
abstract class BaseApiThrottler
{
    /**
     * Returns the rate limit configuration for the API
     *
     * @return array{
     *     requests_per_window: int,
     *     window_seconds: int,
     *     min_delay_between_requests_ms?: int,
     *     safety_threshold?: float
     * }
     */
    abstract protected static function getRateLimitConfig(): array;

    /**
     * Returns the cache key prefix for this API
     */
    abstract protected static function getCacheKeyPrefix(): string;

    /**
     * Atomically check-and-reserve a dispatch slot.
     *
     * Returns 0 when the caller may fire the API request immediately (slot
     * reserved, min-delay already absorbed). Returns a positive integer
     * (seconds to wait) when the caller should back off and retry later.
     *
     * Concurrency model
     * -----------------
     * Previous iterations did the three operations (check min-delay,
     * check window, return wait value) as separate non-atomic reads and
     * left `recordDispatch()` to a later call site. With 20+ Horizon
     * workers against Redis, they'd all read the same stale counter /
     * timestamp simultaneously, all see "free slot", all fire — and the
     * external API would 429 most of them. The naive workaround of
     * rounding every sub-second remainder up to a whole second hid the
     * race at a cost of throughput: a 100 ms deficit became a 1-second
     * reschedule, burning ~90 % of the allowed rate even without
     * contention.
     *
     * This version holds a single per-API cache lock for the entire
     * check-and-reserve critical section so workers serialise naturally
     * at the configured min-delay cadence:
     *
     *   1. Acquire `{prefix}:gate` lock (TTL 10 s, block up to 30 s).
     *      Dead workers release the slot via TTL; lagging callers queue
     *      at the lock instead of spinning against the outer scheduler.
     *   2. INCR the window counter atomically. If the post-INCR count
     *      overshoots the effective limit, DECR back and return
     *      seconds-until-rollover — no other worker ever observes an
     *      over-budget state because the counter is never over-budget
     *      outside a locked region.
     *   3. If min-delay is configured and the deficit is positive,
     *      `usleep` the remainder in milliseconds. Because the lock is
     *      still held, subsequent callers wait their turn instead of
     *      all waking simultaneously and bursting.
     *   4. Stamp `{prefix}:last_dispatch` before releasing so the next
     *      caller sees a fresh timestamp and their min-delay calculation
     *      is accurate.
     *
     * Retry backoff (exponential by retry count) applies only to the
     * window-exceeded return path. The min-delay case sleeps inline and
     * returns 0 — the caller isn't retrying, they're just going after
     * waiting a fraction of a second.
     *
     * `recordDispatch()` becomes a no-op; the slot is reserved here.
     */
    final public static function canDispatch(int $retryCount = 0, ?int $accountId = null, int|string|null $stepId = null): int
    {
        $config = static::getRateLimitConfig();
        $prefix = static::getCacheKeyPrefix();

        $windowSeconds = max(1, (int) ($config['window_seconds'] ?? 1));
        $safetyThreshold = (float) ($config['safety_threshold'] ?? 1.0);
        $effectiveLimit = (int) floor(((int) ($config['requests_per_window'] ?? 0)) * $safetyThreshold);
        $minDelayMs = (int) ($config['min_delay_between_requests_ms'] ?? 0);

        $lock = Cache::lock($prefix.':gate', 10);

        if (! $lock->block(30)) {
            // Couldn't acquire the gate within 30 seconds. Something is
            // badly wrong (stuck worker, Redis issue); back off briefly
            // and retry rather than gamble on a burst.
            return 5;
        }

        try {
            // Window reservation — atomic INCR with rollback on overshoot.
            $windowKey = static::getCurrentWindowKey($prefix, $windowSeconds);
            $newCount = (int) Cache::increment($windowKey);

            if ($newCount === 1) {
                // Cache::increment doesn't always stamp a TTL on the key
                // that creates it; ensure the bucket expires with the window.
                Cache::put($windowKey, 1, $windowSeconds * 2);
            }

            if ($effectiveLimit > 0 && $newCount > $effectiveLimit) {
                Cache::decrement($windowKey);

                $nowTs = Carbon::now()->timestamp;
                $windowEndTs = ((int) floor($nowTs / $windowSeconds) + 1) * $windowSeconds;
                $wait = max(1, $windowEndTs - $nowTs);

                if ($retryCount > 0) {
                    $wait += static::calculateExponentialBackoff($retryCount);
                }

                return $wait;
            }

            // Min-delay — sleep the remainder inline while still holding
            // the lock so subsequent callers don't race against us.
            if ($minDelayMs > 0) {
                $lastDispatch = Cache::get($prefix.':last_dispatch');

                if ($lastDispatch !== null) {
                    $sinceMs = abs(Carbon::now()->diffInMilliseconds($lastDispatch, false));

                    if ($sinceMs < $minDelayMs) {
                        usleep(($minDelayMs - (int) $sinceMs) * 1000);
                    }
                }
            }

            // Record last_dispatch inside the lock so the next caller's
            // min-delay calculation reflects our slot, not the stale one.
            Cache::put($prefix.':last_dispatch', Carbon::now(), max(60, $windowSeconds * 2));

            return 0;
        } finally {
            $lock->release();
        }
    }

    /**
     * Kept public for binary compatibility with the pair-call convention
     * (`canDispatch()` then `recordDispatch()`). The slot is reserved
     * atomically inside `canDispatch()`, so calling this again would
     * double-count the window counter. Intentionally empty.
     */
    final public static function recordDispatch(?int $accountId = null, int|string|null $stepId = null): void
    {
        // Reservation happens in canDispatch() under a cache lock.
    }

    /**
     * Clear all throttling data for this API (useful for testing)
     */
    final public static function reset(): void
    {
        $prefix = static::getCacheKeyPrefix();
        Cache::forget($prefix.':last_dispatch');

        // Clear current and previous windows
        $config = static::getRateLimitConfig();
        $windowSeconds = max(1, $config['window_seconds']); // Guard against division by zero
        $currentWindow = floor(Carbon::now()->timestamp / $windowSeconds);

        for ($i = -2; $i <= 2; $i++) {
            Cache::forget("{$prefix}:window:".($currentWindow + $i));
        }
    }

    /**
     * Calculate exponential backoff delay based on retry count.
     * Formula: retryCount^1.5 + random jitter (0-2 seconds)
     */
    protected static function calculateExponentialBackoff(int $retryCount): int
    {
        // Exponential growth: retryCount^1.5 for smoother curve
        $exponential = (int) ceil(pow($retryCount, exponent: 1.5));

        // Add random jitter (0-2 seconds) to spread out retries
        $jitter = random_int(0, 2);

        return $exponential + $jitter;
    }

    /**
     * Check if minimum delay between requests is satisfied
     */
    protected static function checkMinimumDelay(string $prefix, int $minDelayMs): int
    {
        $lastDispatch = Cache::get($prefix.':last_dispatch');

        if (! $lastDispatch) {
            return 0;
        }

        // diffInMilliseconds returns negative if $lastDispatch is in the past
        // We need the absolute value to get "time since last"
        $timeSinceLastMs = abs(Carbon::now()->diffInMilliseconds($lastDispatch, false));
        $requiredDelayMs = $minDelayMs;

        if ($timeSinceLastMs < $requiredDelayMs) {
            return (int) ceil(($requiredDelayMs - $timeSinceLastMs) / 1000);
        }

        return 0;
    }

    /**
     * Check if we're within the requests-per-window limit
     *
     * @param  float  $safetyThreshold  Percentage of limit to enforce (0.0-1.0). Default 1.0 = 100%
     */
    protected static function checkWindowLimit(string $prefix, int $maxRequests, int $windowSeconds, float $safetyThreshold = 1.0): int
    {
        // Guard against division by zero - default to 1 second window
        if ($windowSeconds <= 0) {
            $windowSeconds = 1;
        }

        // Apply safety threshold to create buffer
        $effectiveLimit = (int) floor($maxRequests * $safetyThreshold);

        $windowKey = static::getCurrentWindowKey($prefix, $windowSeconds);
        $currentCount = Cache::get($windowKey, 0);

        if ($currentCount >= $effectiveLimit) {
            // Calculate how long until this window expires
            $currentWindow = floor(Carbon::now()->timestamp / $windowSeconds);
            $windowStartTime = $currentWindow * $windowSeconds;
            $windowEndTime = $windowStartTime + $windowSeconds;
            $secondsUntilWindowEnd = $windowEndTime - Carbon::now()->timestamp;

            return max(1, (int) ceil($secondsUntilWindowEnd));
        }

        return 0;
    }

    /**
     * Generate a cache key for the current time window
     */
    protected static function getCurrentWindowKey(string $prefix, int $windowSeconds): string
    {
        // Guard against division by zero - default to 1 second window
        if ($windowSeconds <= 0) {
            $windowSeconds = 1;
        }

        $currentWindow = floor(Carbon::now()->timestamp / $windowSeconds);

        return "{$prefix}:window:{$currentWindow}";
    }
}
