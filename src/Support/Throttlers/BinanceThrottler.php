<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Throttlers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Kraite\Core\Abstracts\BaseApiThrottler;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * BinanceThrottler
 *
 * Advanced rate limiter for Binance API that uses response headers to track
 * real-time rate limit consumption across multiple time windows.
 *
 * Binance uses interval-based rate limits with headers like:
 * - X-MBX-USED-WEIGHT-1M: Request weight used in the last minute
 * - X-MBX-USED-WEIGHT-10S: Request weight used in the last 10 seconds
 * - X-MBX-ORDER-COUNT-10S: Orders placed in the last 10 seconds
 *
 * This throttler:
 * 1. Parses response headers after EVERY request
 * 2. Tracks IP ban status (418 responses)
 * 3. Checks rate limit proximity (>80% = throttle)
 * 4. Enforces minimum delays between requests
 *
 * Usage:
 *   $secondsToWait = BinanceThrottler::canDispatch();
 *   if ($secondsToWait > 0) {
 *       // Throttled - retry later
 *       $this->retryJob(now()->addSeconds($secondsToWait));
 *       return;
 *   }
 *   BinanceThrottler::recordDispatch();
 *   // Make API request...
 *   BinanceThrottler::recordResponseHeaders($response);
 */
final class BinanceThrottler extends BaseApiThrottler
{
    /**
     * Pre-flight safety check called before canDispatch().
     * Checks IP ban status and rate limit proximity.
     *
     * @param  int|null  $accountId  Optional account ID for UID-based ORDER limits
     * @param  int|string|null  $stepId  Optional step ID for throttle logging
     * @return int Seconds to wait, or 0 if safe to proceed
     */
    public static function isSafeToDispatch(?int $accountId = null, int|string|null $stepId = null): int
    {
        $prefix = self::getCacheKeyPrefix();

        // 1. Check minimum delay between requests
        $ip = self::getCurrentIp();
        $minDelayMs = config('kraite.throttlers.binance.min_delay_ms', 0);

        if ($minDelayMs > 0) {
            // Check both IP-based timestamp (from recordResponseHeaders)
            // and prefix-based Carbon (from recordDispatch)
            $lastRequest = Cache::get("binance:{$ip}:last_request");
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

        // 2. Check if IP is currently banned (418 response)
        if (self::isCurrentlyBanned()) {
            $secondsRemaining = self::getSecondsUntilBanLifts();

            return $secondsRemaining;
        }

        // 3. Check if approaching any rate limit (>80% threshold)
        $secondsToWait = self::checkRateLimitProximity($accountId, $stepId);
        if ($secondsToWait > 0) {
            return $secondsToWait;
        }

        return 0;
    }

    /**
     * Record Binance response headers after EVERY API request.
     * Parses X-MBX-USED-WEIGHT-* and X-MBX-ORDER-COUNT-* headers and stores them
     * so all workers on the same IP can coordinate.
     *
     * @param  ResponseInterface  $response  The API response
     * @param  int|null  $accountId  Optional account ID for UID-based ORDER limits
     */
    public static function recordResponseHeaders(ResponseInterface $response, ?int $accountId = null): void
    {
        try {
            $ip = self::getCurrentIp();
            $headers = self::normalizeHeaders($response);

            // Record last request timestamp for minimum delay enforcement
            Cache::put("binance:{$ip}:last_request", now()->timestamp, 60);

            // Parse and store weight headers
            $weights = self::parseIntervalHeaders($headers, 'x-mbx-used-weight-');
            foreach ($weights as $data) {
                $interval = $data['interval'];
                $ttl = self::getIntervalTTL($data['intervalNum'], $data['intervalLetter']);

                Cache::put(
                    "binance:{$ip}:weight:{$interval}",
                    $data['value'],
                    $ttl
                );
            }

            // Parse and store order count headers (UID-based, per account)
            $orders = self::parseIntervalHeaders($headers, 'x-mbx-order-count-');
            foreach ($orders as $data) {
                $interval = $data['interval'];
                $ttl = self::getIntervalTTL($data['intervalNum'], $data['intervalLetter']);

                // ORDER limits are per UID (account), so include account ID in cache key
                $key = $accountId !== null
                    ? "binance:{$ip}:uid:{$accountId}:orders:{$interval}"
                    : "binance:{$ip}:orders:{$interval}"; // Fallback for backward compatibility

                Cache::put($key, $data['value'], $ttl);
            }
        } catch (Throwable $e) {
            // Fail silently - don't break the application if Cache fails
        }
    }

    /**
     * Check if the current server IP is currently banned by Binance (418 response).
     */
    public static function isCurrentlyBanned(): bool
    {
        try {
            $ip = self::getCurrentIp();
            $bannedUntil = Cache::get("binance:{$ip}:banned_until");

            return $bannedUntil && now()->timestamp < (int) $bannedUntil;
        } catch (Throwable $e) {
            // Fail safe - if Cache fails, allow the request
            return false;
        }
    }

    /**
     * Record an IP ban in Cache when 418/429 errors with Retry-After occur.
     * This allows all workers on the same IP to coordinate and stop making requests.
     */
    public static function recordIpBan(int $retryAfterSeconds): void
    {
        try {
            $ip = self::getCurrentIp();
            $expiresAt = now()->addSeconds($retryAfterSeconds);

            Cache::put(
                "binance:{$ip}:banned_until",
                $expiresAt->timestamp,
                $retryAfterSeconds
            );
        } catch (Throwable $e) {
            // Fail silently - failing to record ban shouldn't break the app
        }
    }

    /**
     * Basic rate limit config (used as fallback when headers unavailable)
     * Real limits are tracked via response headers.
     *
     * Note: Binance uses weight-based throttling (2400 weight/minute).
     * Setting high fallback to allow base throttler to pass, letting
     * checkRateLimitProximity() handle weight-based limits.
     */
    protected static function getRateLimitConfig(): array
    {
        return [
            'requests_per_window' => config('kraite.throttlers.binance.requests_per_window', 10000),
            'window_seconds' => config('kraite.throttlers.binance.window_seconds', 60),
            'min_delay_between_requests_ms' => config('kraite.throttlers.binance.min_delay_ms', 0),
        ];
    }

    protected static function getCacheKeyPrefix(): string
    {
        return 'binance_throttler';
    }

    /**
     * Get seconds until ban lifts.
     */
    protected static function getSecondsUntilBanLifts(): int
    {
        try {
            $ip = self::getCurrentIp();
            $bannedUntil = Cache::get("binance:{$ip}:banned_until");

            if ($bannedUntil) {
                return max(0, (int) $bannedUntil - now()->timestamp);
            }

            return 0;
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * Check if approaching any rate limit (>80% threshold).
     * Returns seconds to wait if too close to limits.
     *
     * @param  int|null  $accountId  Optional account ID for UID-based ORDER limits
     * @param  int|string|null  $stepId  Optional step ID for throttle logging
     */
    protected static function checkRateLimitProximity(?int $accountId = null, int|string|null $stepId = null): int
    {
        try {
            $ip = self::getCurrentIp();
            $safetyThreshold = config('kraite.throttlers.binance.safety_threshold', 0.8);

            // Get rate limits from config or use conservative defaults
            $rateLimits = config('kraite.throttlers.binance.rate_limits', [
                ['type' => 'REQUEST_WEIGHT', 'interval' => '1m', 'limit' => 1200],
                ['type' => 'REQUEST_WEIGHT', 'interval' => '10s', 'limit' => 100],
                ['type' => 'ORDERS', 'interval' => '10s', 'limit' => 50],
            ]);

            foreach ($rateLimits as $rateLimit) {
                $interval = $rateLimit['interval'];
                $limit = $rateLimit['limit'];
                $type = $rateLimit['type'];

                // ORDER limits are per UID (account), WEIGHT limits are per IP
                if ($type === 'ORDERS' && $accountId !== null) {
                    $key = "binance:{$ip}:uid:{$accountId}:orders:{$interval}";
                } elseif ($type === 'ORDERS') {
                    $key = "binance:{$ip}:orders:{$interval}"; // Fallback
                } else {
                    $key = "binance:{$ip}:weight:{$interval}";
                }

                $current = Cache::get($key) ?? 0;
                $percentage = $limit > 0 ? ($current / $limit) : 0;

                if ($percentage > $safetyThreshold) {
                    // Calculate time until this window resets
                    $waitTime = self::calculateWindowResetTime($interval);

                    return $waitTime;
                }
            }

            return 0;
        } catch (Throwable $e) {
            // Fail safe - if Cache fails, allow the request
            return 0;
        }
    }

    /**
     * Calculate seconds until a rate limit window resets.
     */
    protected static function calculateWindowResetTime(string $interval): int
    {
        // Parse interval like "1m", "10s"
        if (preg_match('/^(\d+)([smhd])$/i', $interval, matches: $matches)) {
            $intervalNum = (int) $matches[1];
            $intervalLetter = mb_strtolower($matches[2]);

            $seconds = match ($intervalLetter) {
                's' => $intervalNum,
                'm' => $intervalNum * 60,
                'h' => $intervalNum * 3600,
                'd' => $intervalNum * 86400,
                default => 60,
            };

            // Return time until current window expires (conservative estimate)
            return min($seconds, 60); // Max 60 seconds wait
        }

        return 5; // Default conservative wait
    }

    /**
     * Parse Binance interval headers (e.g., X-MBX-USED-WEIGHT-1M, X-MBX-ORDER-COUNT-10S).
     * Returns array keyed by interval string (e.g., '1M', '10S') with parsed data.
     *
     * @param  array  $headers  Normalized headers (lowercase keys)
     * @param  string  $prefix  Header prefix to match (e.g., 'x-mbx-used-weight-')
     * @return array Array of ['intervalNum' => int, 'intervalLetter' => string, 'value' => int, 'interval' => string]
     */
    protected static function parseIntervalHeaders(array $headers, string $prefix): array
    {
        $result = [];

        foreach ($headers as $key => $value) {
            // Match headers like: x-mbx-used-weight-1m => 50
            if (! Str::startsWith($key, $prefix)) {
                continue;
            }

            // Extract interval part (e.g., "1m" from "x-mbx-used-weight-1m")
            $interval = Str::after($key, $prefix);

            // Parse intervalNum and intervalLetter (e.g., "1m" => num=1, letter=m)
            if (preg_match('/^(\d+)([smhd])$/i', $interval, matches: $matches)) {
                $result[$interval] = [
                    'intervalNum' => (int) $matches[1],
                    'intervalLetter' => mb_strtolower($matches[2]),
                    'value' => (int) $value,
                    'interval' => $interval,
                ];
            }
        }

        return $result;
    }

    /**
     * Calculate TTL in seconds for a given interval.
     */
    protected static function getIntervalTTL(int $intervalNum, string $intervalLetter): int
    {
        return match (mb_strtolower($intervalLetter)) {
            's' => $intervalNum,
            'm' => $intervalNum * 60,
            'h' => $intervalNum * 3600,
            'd' => $intervalNum * 86400,
            default => 60, // Default to 1 minute
        };
    }

    /**
     * Normalize headers (lower-case keys, comma-joined values).
     */
    protected static function normalizeHeaders($msg): array
    {
        $headers = [];
        foreach ($msg->getHeaders() as $k => $vals) {
            $headers[mb_strtolower($k)] = implode(separator: ', ', array: $vals);
        }

        return $headers;
    }

    /**
     * Get current server IP address.
     */
    protected static function getCurrentIp(): string
    {
        return \Kraite\Core\Models\Kraite::ip();
    }
}
