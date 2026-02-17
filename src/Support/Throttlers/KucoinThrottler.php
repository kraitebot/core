<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Throttlers;

use Illuminate\Support\Facades\Cache;
use Kraite\Core\Abstracts\BaseApiThrottler;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * KucoinThrottler
 *
 * Rate limiter for KuCoin Futures API based on their documented limits:
 * - Public endpoints: 30 requests per 3 seconds per IP
 * - Private endpoints: 75 requests per 3 seconds per IP
 *
 * KuCoin Ban Behavior:
 * - Exceeding limits triggers HTTP 429 "Too Many Requests"
 * - Ban duration varies based on severity
 *
 * This throttler:
 * 1. Enforces conservative rate limiting to stay under 25 req/3s (85% of public limit)
 * 2. Tracks IP ban status (429 responses)
 * 3. Enforces minimum delays between requests
 * 4. Uses sliding window to prevent bursts
 *
 * Usage:
 *   $secondsToWait = KucoinThrottler::canDispatch();
 *   if ($secondsToWait > 0) {
 *       // Throttled - retry later
 *       $this->retryJob(now()->addSeconds($secondsToWait));
 *       return;
 *   }
 *   KucoinThrottler::recordDispatch();
 *   // Make API request...
 *   KucoinThrottler::recordResponseHeaders($response); // Optional
 */
final class KucoinThrottler extends BaseApiThrottler
{
    /**
     * Pre-flight safety check called before canDispatch().
     * Checks IP ban status, minimum delay, and rate limit threshold.
     *
     * @param  int|null  $accountId  Optional account ID (not used by KuCoin - all limits are IP-based)
     * @param  int|string|null  $stepId  Optional step ID for throttle logging
     * @return int Seconds to wait, or 0 if safe to proceed
     */
    public static function isSafeToDispatch(?int $accountId = null, int|string|null $stepId = null): int
    {
        $prefix = self::getCacheKeyPrefix();

        // 1. Check minimum delay between requests
        $ip = self::getCurrentIp();
        $minDelayMs = config('kraite.throttlers.kucoin.min_delay_ms', 0);

        if ($minDelayMs > 0) {
            // Check both IP-based timestamp (from recordResponseHeaders)
            // and prefix-based Carbon (from recordDispatch)
            $lastRequest = Cache::get("kucoin:{$ip}:last_request");
            $lastDispatch = Cache::get($prefix.':last_dispatch');

            $lastTimestamp = null;
            if ($lastRequest) {
                $lastTimestamp = $lastRequest;
            } elseif ($lastDispatch && $lastDispatch instanceof \Illuminate\Support\Carbon) {
                $lastTimestamp = $lastDispatch->timestamp;
            }

            if ($lastTimestamp) {
                $minDelaySeconds = $minDelayMs / 1000;
                $elapsedSeconds = now()->timestamp - $lastTimestamp;

                if ($elapsedSeconds < $minDelaySeconds) {
                    $waitSeconds = (int) ceil($minDelaySeconds - $elapsedSeconds);

                    return $waitSeconds;
                }
            }
        }

        // 2. Check if IP is currently banned (429 response)
        if (self::isCurrentlyBanned()) {
            $secondsRemaining = self::getSecondsUntilBanLifts();

            return $secondsRemaining;
        }

        // 3. Use base class window limit check
        return 0;
    }

    /**
     * Record KuCoin response headers.
     * KuCoin doesn't provide specific rate limit headers,
     * but we track request timestamps for minimum delay enforcement.
     *
     * @param  ResponseInterface  $response  The API response
     * @param  int|null  $accountId  Optional account ID (not used by KuCoin - all limits are IP-based)
     */
    public static function recordResponseHeaders(ResponseInterface $response, ?int $accountId = null): void
    {
        try {
            $ip = self::getCurrentIp();

            // Record last request timestamp for minimum delay enforcement
            Cache::put("kucoin:{$ip}:last_request", now()->timestamp, 60);
        } catch (Throwable $e) {
            // Fail silently - don't break the application if Cache fails
        }
    }

    /**
     * Check if the current server IP is currently banned by KuCoin (429 response).
     */
    public static function isCurrentlyBanned(): bool
    {
        try {
            $ip = self::getCurrentIp();
            $bannedUntil = Cache::get("kucoin:{$ip}:banned_until");

            return $bannedUntil && now()->timestamp < (int) $bannedUntil;
        } catch (Throwable $e) {
            // Fail safe - if Cache fails, allow the request
            return false;
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
                "kucoin:{$ip}:banned_until",
                $expiresAt->timestamp,
                $retryAfterSeconds
            );
        } catch (Throwable $e) {
            // Fail silently - failing to record ban shouldn't break the app
        }
    }

    /**
     * KuCoin Rate Limits (configurable via config/kraite.php)
     *
     * Default configuration: Balanced settings to avoid 429 ban
     * - Public: 30 requests per 3 seconds
     * - Private: 75 requests per 3 seconds
     * - We use 25/3s to stay safe (85% of public limit)
     * - Uses sliding window algorithm for burst protection
     *
     * To adjust, update config/kraite.php:
     * 'throttlers.kucoin.requests_per_window'
     * 'throttlers.kucoin.window_seconds'
     */
    protected static function getRateLimitConfig(): array
    {
        return [
            'requests_per_window' => config('kraite.throttlers.kucoin.requests_per_window', 25), // 85% of 30
            'window_seconds' => config('kraite.throttlers.kucoin.window_seconds', 3),
            'min_delay_between_requests_ms' => config('kraite.throttlers.kucoin.min_delay_ms', 100),
            'safety_threshold' => config('kraite.throttlers.kucoin.safety_threshold', 0.85),
        ];
    }

    protected static function getCacheKeyPrefix(): string
    {
        return 'kucoin_throttler';
    }

    /**
     * Get seconds until ban lifts.
     */
    protected static function getSecondsUntilBanLifts(): int
    {
        try {
            $ip = self::getCurrentIp();
            $bannedUntil = Cache::get("kucoin:{$ip}:banned_until");

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
        return \Kraite\Core\Models\Engine::ip();
    }
}
