<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Throttlers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Kraite\Core\Abstracts\BaseApiThrottler;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * BitgetThrottler
 *
 * Rate limiter for BitGet Futures API based on their documented limits:
 * - Public endpoints: 20 requests per second per IP
 * - Private endpoints: 10 requests per second per IP (orders)
 * - Overall: 6000 requests per minute per IP
 *
 * BitGet Ban Behavior:
 * - Exceeding limits triggers HTTP 429 "Too Many Requests"
 * - Ban duration varies based on severity
 *
 * This throttler:
 * 1. Enforces conservative rate limiting to stay under 90 req/min (85% of safe limit)
 * 2. Tracks IP ban status (429 responses)
 * 3. Enforces minimum delays between requests
 * 4. Uses sliding window to prevent bursts
 *
 * Usage:
 *   $secondsToWait = BitgetThrottler::canDispatch();
 *   if ($secondsToWait > 0) {
 *       // Throttled - retry later
 *       $this->retryJob(now()->addSeconds($secondsToWait));
 *       return;
 *   }
 *   BitgetThrottler::recordDispatch();
 *   // Make API request...
 *   BitgetThrottler::recordResponseHeaders($response); // Optional
 */
final class BitgetThrottler extends BaseApiThrottler
{
    /**
     * Pre-flight safety check called before canDispatch().
     * Checks IP ban status, minimum delay, and rate limit threshold.
     *
     * @param  int|null  $accountId  Optional account ID (not used by BitGet - all limits are IP-based)
     * @param  int|string|null  $stepId  Optional step ID for throttle logging
     * @return int Seconds to wait, or 0 if safe to proceed
     */
    /**
     * Pre-flight safety check — returns milliseconds to wait, or 0 if OK.
     */
    public static function isSafeToDispatch(?int $accountId = null, int|string|null $stepId = null): int
    {
        $prefix = self::getCacheKeyPrefix();

        $ip = self::getCurrentIp();
        $minDelayMs = (int) config('kraite.throttlers.bitget.min_delay_ms', 0);

        if ($minDelayMs > 0) {
            $lastRequestTs = Cache::get("bitget:{$ip}:last_request");
            $lastDispatch = Cache::get($prefix.':last_dispatch');

            $nowMs = (int) round(now()->getPreciseTimestamp(3));
            $elapsedMs = PHP_INT_MAX;

            if ($lastRequestTs) {
                $elapsedMs = min($elapsedMs, ($nowMs / 1000 - (int) $lastRequestTs) * 1000);
            }

            if ($lastDispatch instanceof \Illuminate\Support\Carbon) {
                $elapsedMs = min(
                    $elapsedMs,
                    abs(now()->diffInMilliseconds($lastDispatch, false))
                );
            }

            if ($elapsedMs < $minDelayMs) {
                return $minDelayMs - (int) $elapsedMs;
            }
        }

        if (self::isCurrentlyBanned()) {
            return self::getSecondsUntilBanLifts() * 1000;
        }

        return 0;
    }

    /**
     * Record BitGet response headers.
     * BitGet doesn't provide specific rate limit headers,
     * but we track request timestamps for minimum delay enforcement.
     *
     * @param  ResponseInterface  $response  The API response
     * @param  int|null  $accountId  Optional account ID (not used by BitGet - all limits are IP-based)
     */
    public static function recordResponseHeaders(ResponseInterface $response, ?int $accountId = null): void
    {
        try {
            $ip = self::getCurrentIp();

            // Record last request timestamp for minimum delay enforcement
            Cache::put("bitget:{$ip}:last_request", now()->timestamp, 60);
        } catch (Throwable $e) {
            // Fail silently - don't break the application if Cache fails
        }
    }

    /**
     * Check if the current server IP is currently banned by BitGet (429 response).
     */
    public static function isCurrentlyBanned(): bool
    {
        try {
            $ip = self::getCurrentIp();
            $bannedUntil = Cache::get("bitget:{$ip}:banned_until");

            return $bannedUntil && now()->timestamp < (int) $bannedUntil;
        } catch (Throwable $e) {
            // Fail CLOSED on cache failure — see BinanceThrottler for the
            // full rationale.
            Log::channel('jobs')->warning('[BitgetThrottler] isCurrentlyBanned cache failure — failing closed', [
                'error' => $e->getMessage(),
            ]);

            return true;
        }
    }

    /**
     * Record an IP ban in Cache when 429 errors occur.
     *
     * @param  int  $retryAfterSeconds  Seconds until ban lifts (default: 30 seconds)
     */
    public static function recordIpBan(int $retryAfterSeconds = 30): void
    {
        try {
            $ip = self::getCurrentIp();
            $expiresAt = now()->addSeconds($retryAfterSeconds);

            Cache::put(
                "bitget:{$ip}:banned_until",
                $expiresAt->timestamp,
                $retryAfterSeconds
            );
        } catch (Throwable $e) {
            // Fail silently - failing to record ban shouldn't break the app
        }
    }

    /**
     * BitGet Rate Limits (configurable via config/kraite.php)
     *
     * Default configuration: Balanced settings to avoid 429 ban
     * - Overall: 6000 requests per minute per IP
     * - We use 90/min to stay safe (conservative limit)
     * - Uses sliding window algorithm for burst protection
     *
     * To adjust, update config/kraite.php:
     * 'throttlers.bitget.requests_per_window'
     * 'throttlers.bitget.window_seconds'
     */
    protected static function getRateLimitConfig(): array
    {
        return [
            'requests_per_window' => config('kraite.throttlers.bitget.requests_per_window', 90),
            'window_seconds' => config('kraite.throttlers.bitget.window_seconds', 60),
            'min_delay_between_requests_ms' => config('kraite.throttlers.bitget.min_delay_ms', 50),
            'safety_threshold' => config('kraite.throttlers.bitget.safety_threshold', 0.85),
        ];
    }

    protected static function getCacheKeyPrefix(): string
    {
        return 'bitget_throttler';
    }

    /**
     * Get seconds until ban lifts.
     */
    protected static function getSecondsUntilBanLifts(): int
    {
        try {
            $ip = self::getCurrentIp();
            $bannedUntil = Cache::get("bitget:{$ip}:banned_until");

            if ($bannedUntil) {
                return max(0, (int) $bannedUntil - now()->timestamp);
            }

            return 0;
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * Get current server IP address.
     */
    protected static function getCurrentIp(): string
    {
        return \Kraite\Core\Models\Kraite::ip();
    }
}
