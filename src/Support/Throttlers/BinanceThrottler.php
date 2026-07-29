<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Throttlers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Kraite\Core\Abstracts\BaseApiThrottler;
use Kraite\Core\Exceptions\NonNotifiableException;
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
     * Pace one real HTTP attempt against the shared per-IP weight budget.
     *
     * Why this exists alongside isSafeToDispatch(): that one is a *step*
     * admission gate, evaluated once before a job body runs. A job that
     * loops — one call per position, per symbol, per income type — is
     * checked for its first call and unmetered for the rest. Worse, a
     * console command, a daemon or an ad-hoc script never crosses a step
     * boundary at all, so it spends the budget the trading engine depends
     * on without ever being asked. This is the per-attempt brake that
     * covers all of them.
     *
     * It is deliberately softer than the step-level gate. A step can be
     * rescheduled for nothing, so refusing there is free. A live HTTP
     * attempt has no such escape hatch — refusing it would fail callers
     * that succeed today, including the once-a-minute listen-key refresh
     * whose failure silently expires the user-data stream. So budget
     * pressure degrades to a bounded pause and then proceeds: a slower
     * burst is worth far more than a new way to fail.
     *
     * A known IP ban is the one exception. Binance 418 bans run from two
     * minutes to three days, so no bounded pause is meaningful and every
     * further request risks extending the ban. That case refuses outright.
     */
    public static function throttleRequest(): void
    {
        $maxSleepMs = max(0, (int) config('kraite.throttlers.binance.client_max_sleep_ms', 1000));

        $banWaitSeconds = self::readBanWaitSeconds();

        // Null means the ban ledger itself is unreadable. The step-level gate
        // fails closed here; this one cannot, for the reason above. Pause the
        // ceiling — still stricter than the unmetered status quo — and go.
        if ($banWaitSeconds === null) {
            self::pause($maxSleepMs);

            return;
        }

        // Non-notifiable on purpose. The ban was already announced once, by the
        // observer that saw the 418/429 which recorded it. Every call refused
        // afterwards is the ban working as intended, not a new incident — and
        // alerting per refused call is exactly how an error storm starts.
        if ($banWaitSeconds > 0) {
            throw new NonNotifiableException(
                "Binance request refused: this IP is banned for a further {$banWaitSeconds}s."
            );
        }

        // Weight proximity only. ORDER budgets are UID-scoped and the client
        // has no account context, so omitting the account id keeps this to the
        // per-IP weight rows rather than silently consulting an unrelated key.
        $proximityWaitMs = self::checkRateLimitProximity();

        self::pause(min($proximityWaitMs, $maxSleepMs));
    }

    /**
     * Pre-flight safety check called before canDispatch().
     * Checks IP ban status and rate limit proximity.
     *
     * @param  int|null  $accountId  Optional account ID for UID-based ORDER limits
     * @param  int|string|null  $stepId  Optional step ID for throttle logging
     * @return int Seconds to wait, or 0 if safe to proceed
     */
    /**
     * Pre-flight safety check — returns milliseconds to wait, or 0 if OK.
     *
     * Minimum-delay calculations preserve millisecond precision so the
     * step-dispatcher's retry scheduling (against the TIMESTAMP(3)
     * `dispatch_after` column) can honour sub-second deficits. IP-ban
     * and rate-limit-proximity paths promote their second-precision
     * values to milliseconds for a uniform contract.
     */
    public static function isSafeToDispatch(?int $accountId = null, int|string|null $stepId = null): int
    {
        $prefix = self::getCacheKeyPrefix();

        // 1. Minimum delay between requests — ms precision.
        $ip = self::getCurrentIp();
        $minDelayMs = (int) config('kraite.throttlers.binance.min_delay_ms', 0);

        if ($minDelayMs > 0) {
            try {
                // Two possible sources for the "last request happened" stamp:
                // `last_request` is a unix-seconds integer written by
                // recordResponseHeaders (second-precision only), while
                // `:last_dispatch` is a Carbon with millisecond precision
                // written by the base-class canDispatch when it reserves a slot.
                // Prefer whichever is most recent.
                $lastRequestTs = Cache::get("binance:{$ip}:last_request");
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
            } catch (Throwable $exception) {
                return self::cacheFailureBackoffMs($exception, 'minimum-delay');
            }
        }

        // 2. IP ban (418 response) — seconds remaining, promoted to ms.
        if (self::isCurrentlyBanned()) {
            return self::getSecondsUntilBanLifts() * 1000;
        }

        // 3. Rate-limit proximity (weight budget, ORDER budget) — ms.
        $msToWait = self::checkRateLimitProximity($accountId, $stepId);
        if ($msToWait > 0) {
            return $msToWait;
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
            Log::channel('jobs')->warning('[BinanceThrottler] response-header ledger write failed', [
                'error' => $e->getMessage(),
            ]);
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
            // Fail CLOSED on cache failure for trading-grade safety.
            // Pre-fix, a Redis outage would make every worker bypass
            // the IP-ban gate simultaneously — exactly when distributed
            // coordination matters most. Treat the cache being
            // unreachable as "I cannot prove we're not banned" =
            // assume banned. The caller's reschedule logic backs off
            // until cache is reachable again. Visible failure is
            // strongly preferable to silent unlimited exchange traffic.
            Log::channel('jobs')->warning('[BinanceThrottler] isCurrentlyBanned cache failure — failing closed', [
                'error' => $e->getMessage(),
            ]);

            return true;
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
            Log::channel('jobs')->critical('[BinanceThrottler] failed to record exchange IP ban', [
                'retry_after_seconds' => $retryAfterSeconds,
                'error' => $e->getMessage(),
            ]);
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
            'requests_per_window' => config('kraite.throttlers.binance.requests_per_window', 2040),
            'window_seconds' => config('kraite.throttlers.binance.window_seconds', 60),
            'min_delay_between_requests_ms' => config('kraite.throttlers.binance.min_delay_ms', 0),
            'safety_threshold' => 1.0,
            'atomic_reservation' => true,
            'cache_failure_backoff_ms' => config('kraite.throttlers.binance.cache_failure_backoff_ms', 30000),
            'reservation_contention_backoff_ms' => config('kraite.throttlers.binance.reservation_contention_backoff_ms', 50),
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
            return (int) ceil(self::cacheFailureBackoffMs($e, 'ban-lift') / 1000);
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

                if ($percentage >= $safetyThreshold) {
                    return self::calculateWindowResetTime($interval) * 1000;
                }
            }

            return 0;
        } catch (Throwable $e) {
            return self::cacheFailureBackoffMs($e, 'rate-limit-proximity');
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

            $secondsUntilReset = $seconds - (now()->timestamp % $seconds);

            return max(1, min($secondsUntilReset, 60));
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

    /**
     * Seconds remaining on a known IP ban, 0 when demonstrably clear, or null
     * when the ban ledger could not be read at all.
     */
    private static function readBanWaitSeconds(): ?int
    {
        try {
            $ip = self::getCurrentIp();
            $bannedUntil = Cache::get("binance:{$ip}:banned_until");

            if (! $bannedUntil) {
                return 0;
            }

            return max(0, (int) $bannedUntil - now()->timestamp);
        } catch (Throwable $exception) {
            Log::channel('jobs')->warning('[BinanceThrottler] client gate could not read ban state — pausing instead of refusing', [
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private static function pause(int $milliseconds): void
    {
        if ($milliseconds > 0) {
            Sleep::for($milliseconds)->milliseconds();
        }
    }

    private static function cacheFailureBackoffMs(Throwable $exception, string $check): int
    {
        $backoffMs = max(
            1000,
            (int) config('kraite.throttlers.binance.cache_failure_backoff_ms', 30000),
        );

        Log::channel('jobs')->warning('[BinanceThrottler] cache coordination failed — backing off', [
            'check' => $check,
            'error' => $exception->getMessage(),
            'backoff_ms' => $backoffMs,
        ]);

        return $backoffMs;
    }
}
