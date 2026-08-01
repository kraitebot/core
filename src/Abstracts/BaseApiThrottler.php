<?php

declare(strict_types=1);

namespace Kraite\Core\Abstracts;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

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
     *     safety_threshold?: float,
     *     atomic_reservation?: bool,
     *     cache_failure_backoff_ms?: int,
     *     reservation_contention_backoff_ms?: int
     * }
     */
    abstract protected static function getRateLimitConfig(): array;

    /**
     * Returns the cache key prefix for this API
     */
    abstract protected static function getCacheKeyPrefix(): string;

    /**
     * Check if we can dispatch a request to the API right now.
     *
     * Returns 0 when the caller may fire immediately.
     * Returns a positive integer **in milliseconds** when the caller must wait.
     *
     * Milliseconds (not seconds) because `BaseApiableJob::shouldStartOrThrottle`
     * forwards this value to the step-dispatcher's `jobBackoffMs`, which
     * schedules retries against the millisecond-precision `dispatch_after`
     * column (TIMESTAMP(3)). Sub-second deficits (e.g. 83 ms remaining
     * against a 200 ms min-delay) reschedule at their exact remainder
     * instead of being rounded up to the next whole second — that was
     * historically costing 50–75 % of the configured rate budget on hot
     * APIs (TAAPI, CMC) because every retry paid an extra 500–900 ms of
     * unnecessary wait.
     *
     * @param  int  $retryCount  Number of retries already attempted (for exponential backoff)
     * @param  int|null  $accountId  Optional account ID for UID-based rate limits (e.g., ORDER limits)
     * @param  int|string|null  $stepId  Optional step ID for throttle logging
     * @return int Milliseconds to wait, or 0 if OK to proceed
     */
    final public static function canDispatch(int $retryCount = 0, ?int $accountId = null, int|string|null $stepId = null): int
    {
        $config = static::getRateLimitConfig();
        $prefix = static::getCacheKeyPrefix();

        if (($config['atomic_reservation'] ?? false) === true) {
            $msToWait = static::reserveDispatchAtomically($prefix, $config);

            if ($retryCount > 0 && $msToWait > 0) {
                $msToWait += static::calculateExponentialBackoff($retryCount) * 1000;
            }

            return $msToWait;
        }

        // Minimum delay between requests — sub-second precision preserved.
        if (isset($config['min_delay_between_requests_ms'])) {
            try {
                $msToWait = static::checkMinimumDelay($prefix, $config['min_delay_between_requests_ms']);
            } catch (Throwable $exception) {
                $cacheFailureBackoffMs = max(1, (int) ($config['cache_failure_backoff_ms'] ?? 30000));

                Log::channel('jobs')->warning('[API-THROTTLER] minimum-delay cache failure — backing off', [
                    'throttler' => static::class,
                    'error' => $exception->getMessage(),
                    'backoff_ms' => $cacheFailureBackoffMs,
                ]);

                return $cacheFailureBackoffMs;
            }

            if ($msToWait > 0) {
                return $msToWait;
            }
        }

        // Requests per window limit — seconds, promoted to milliseconds.
        $safetyThreshold = $config['safety_threshold'] ?? 1.0;
        $msToWait = static::checkWindowLimit(
            $prefix,
            $config['requests_per_window'],
            $config['window_seconds'],
            $safetyThreshold
        );

        if ($retryCount > 0 && $msToWait > 0) {
            $msToWait += static::calculateExponentialBackoff($retryCount) * 1000;
        }

        return $msToWait;
    }

    /**
     * Record that a dispatch happened right now
     *
     * @param  int|null  $accountId  Optional account ID for UID-based rate limits (e.g., ORDER limits)
     * @param  int|string|null  $stepId  Optional step ID for throttle logging
     */
    final public static function recordDispatch(?int $accountId = null, int|string|null $stepId = null): void
    {
        $prefix = static::getCacheKeyPrefix();
        $config = static::getRateLimitConfig();

        // Opt-in throttlers reserve both the window counter and minimum-delay
        // timestamp inside canDispatch(). Recording again here would double
        // count every admitted request. Other throttlers keep their existing
        // behavior unchanged.
        if (($config['atomic_reservation'] ?? false) === true) {
            return;
        }

        // Update last dispatch timestamp.
        Cache::put($prefix.':last_dispatch', static::currentTimeInMilliseconds(), $config['window_seconds']);

        // Increment counter for current window
        $windowKey = static::getCurrentWindowKey($prefix, $config['window_seconds']);
        $currentCount = Cache::get($windowKey, 0);
        $newCount = $currentCount + 1;
        Cache::put($windowKey, $newCount, $config['window_seconds'] * 2);
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
     * Check if the minimum delay between requests is satisfied.
     *
     * Returns the remaining deficit in **milliseconds** — no rounding.
     * A caller that arrives 183 ms into a 200 ms min-delay gets told to
     * wait 17 ms, not "round up to 1 second". Zero means OK to proceed.
     */
    protected static function checkMinimumDelay(string $prefix, int $minDelayMs): int
    {
        $lastDispatchMs = static::lastDispatchMilliseconds($prefix);

        if ($lastDispatchMs === null) {
            return 0;
        }

        $timeSinceLastMs = max(0, static::currentTimeInMilliseconds() - $lastDispatchMs);

        if ($timeSinceLastMs < $minDelayMs) {
            return $minDelayMs - (int) $timeSinceLastMs;
        }

        return 0;
    }

    protected static function lastDispatchMilliseconds(string $prefix): ?int
    {
        $lastDispatchMs = Cache::get($prefix.':last_dispatch');

        if ($lastDispatchMs === null) {
            return null;
        }

        if (is_int($lastDispatchMs)) {
            return $lastDispatchMs;
        }

        if (is_string($lastDispatchMs) && ctype_digit($lastDispatchMs)) {
            $normalizedTimestamp = filter_var($lastDispatchMs, FILTER_VALIDATE_INT);

            if ($normalizedTimestamp !== false) {
                return $normalizedTimestamp;
            }
        }

        throw new UnexpectedValueException('API throttle dispatch timestamp must be an integer.');
    }

    protected static function currentTimeInMilliseconds(): int
    {
        return (int) round(Carbon::now()->getPreciseTimestamp(3));
    }

    /**
     * Check the per-window request-count cap.
     *
     * Returns the remaining time until the current window rolls over, in
     * **milliseconds**. Window arithmetic is still done in whole seconds
     * internally (windows are typically 15-60s long), but the return value
     * is converted up to ms for a uniform `canDispatch` contract.
     *
     * @param  float  $safetyThreshold  Percentage of limit to enforce (0.0-1.0). Default 1.0 = 100%
     */
    protected static function checkWindowLimit(string $prefix, int $maxRequests, int $windowSeconds, float $safetyThreshold = 1.0): int
    {
        if ($windowSeconds <= 0) {
            $windowSeconds = 1;
        }

        $effectiveLimit = (int) floor($maxRequests * $safetyThreshold);

        $windowKey = static::getCurrentWindowKey($prefix, $windowSeconds);
        $currentCount = Cache::get($windowKey, 0);

        if ($currentCount >= $effectiveLimit) {
            return static::millisecondsUntilWindowReset($windowSeconds);
        }

        return 0;
    }

    /**
     * Atomically claim one request slot and the minimum-delay timestamp.
     *
     * This is opt-in because changing reservation semantics for every provider
     * at once would alter independently tuned throttlers. Binance enables it:
     * its many workers share one IP budget, so check-then-record can overshoot
     * by worker concurrency and read-modify-write can lose counter increments.
     *
     * @param  array<string, mixed>  $config
     */
    protected static function reserveDispatchAtomically(string $prefix, array $config): int
    {
        $windowSeconds = max(1, (int) $config['window_seconds']);
        $minDelayMs = max(0, (int) ($config['min_delay_between_requests_ms'] ?? 0));
        $cacheFailureBackoffMs = max(1, (int) ($config['cache_failure_backoff_ms'] ?? 30000));
        $contentionBackoffMs = max(1, (int) ($config['reservation_contention_backoff_ms'] ?? 50));

        try {
            $lock = Cache::lock($prefix.':reservation', 5);

            if (! $lock->get()) {
                return max($contentionBackoffMs, $minDelayMs);
            }

            try {
                if ($minDelayMs > 0) {
                    $msToWait = static::checkMinimumDelay($prefix, $minDelayMs);
                    if ($msToWait > 0) {
                        return $msToWait;
                    }
                }

                $maxRequests = max(1, (int) $config['requests_per_window']);
                $safetyThreshold = (float) ($config['safety_threshold'] ?? 1.0);
                $effectiveLimit = max(1, (int) floor($maxRequests * $safetyThreshold));
                $windowKey = static::getCurrentWindowKey($prefix, $windowSeconds);

                // add() creates the key with its TTL exactly once. increment()
                // is atomic, so concurrent workers cannot lose reservations.
                Cache::add($windowKey, 0, $windowSeconds * 2);
                $incremented = Cache::increment($windowKey);
                if ($incremented === false) {
                    throw new RuntimeException('Unable to increment API throttle reservation counter.');
                }
                $reservedCount = (int) $incremented;

                if ($reservedCount > $effectiveLimit) {
                    Cache::decrement($windowKey);

                    return static::millisecondsUntilWindowReset($windowSeconds);
                }

                if (! Cache::put($prefix.':last_dispatch', static::currentTimeInMilliseconds(), $windowSeconds)) {
                    throw new RuntimeException('Unable to persist API throttle dispatch timestamp.');
                }

                return 0;
            } finally {
                $lock->release();
            }
        } catch (Throwable $exception) {
            Log::channel('jobs')->warning('[API-THROTTLER] atomic reservation cache failure — backing off', [
                'throttler' => static::class,
                'error' => $exception->getMessage(),
                'backoff_ms' => $cacheFailureBackoffMs,
            ]);

            return $cacheFailureBackoffMs;
        }
    }

    protected static function millisecondsUntilWindowReset(int $windowSeconds): int
    {
        $windowSeconds = max(1, $windowSeconds);
        $nowMs = static::currentTimeInMilliseconds();
        $windowEndMs = ((int) floor($nowMs / ($windowSeconds * 1000)) + 1) * $windowSeconds * 1000;

        return max(1, $windowEndMs - $nowMs);
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
