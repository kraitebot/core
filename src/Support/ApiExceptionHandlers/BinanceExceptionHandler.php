<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiExceptionHandlers;

use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Concerns\ApiExceptionHelpers;
use Kraite\Core\Support\Throttlers\BinanceThrottler;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * BinanceExceptionHandler
 *
 * Focus:
 * • Keep the trait generic; put Binance-specific rules here.
 * • Respect Retry-After and the new IP/Order/Weight semantics.
 * • Treat 418 as a rate-limit (temporary IP ban), not a “forbidden” credential error.
 * • Avoid introducing any Redis/local throttling here (handled elsewhere in your app).
 */
final class BinanceExceptionHandler extends BaseExceptionHandler
{
    use ApiExceptionHelpers {
        rateLimitUntil as baseRateLimitUntil;
    }

    // SYNCHRONIZED
    /**
     * Server forbidden — real exchange-level IP bans (server cannot make ANY calls).
     * HTTP 418: IP auto-banned by Binance (temporary or permanent ban)
     *
     * NOTE: This array is used by the generic isForbidden() method.
     * For more specific classification, use:
     * - isIpNotWhitelisted(): User forgot to whitelist IP (-2015 with IP message)
     * - isIpRateLimited(): Temporary ban (418 with Retry-After)
     * - isIpBanned(): Permanent ban (418 without Retry-After or very long duration)
     * - isAccountBlocked(): API key revoked/invalid (-2015 with key message)
     */
    public array $serverForbiddenHttpCodes = [418];

    /**
     * IP not whitelisted by user on their API key settings.
     * HTTP 401 with -2015: "Invalid API-key, IP, or permissions for action"
     * When the message contains "IP" it indicates whitelist issue.
     *
     * @var array<int, array<int, int>|int>
     */
    public array $ipNotWhitelistedHttpCodes = [
        401 => [-2015],
    ];

    /**
     * Account blocked — API key revoked, disabled, or permission issues.
     * HTTP 401 with -2015: When message indicates key/permission issue (not IP).
     * HTTP 401 with -2014: API key format invalid.
     *
     * @var array<int, array<int, int>|int>
     */
    public array $accountBlockedHttpCodes = [
        401 => [-2015, -2014],
    ];

    // SYNCHRONIZED
    /**
     * Server rate-limited — slow down and back off.
     * • HTTP 429: Too Many Requests (may or may not carry Retry-After)
     * • HTTP 400 with -1003: Too many requests (WAF limit)
     *
     * NOTE: 401/-2015 is handled separately as an API key configuration issue.
     * NOTE: 418 is NOT here (it's a server forbidden error, not a rate limit)
     * NOTE: -1015 (order rate limit) is NOT here (action-specific, not general rate limit)
     */
    public array $serverRateLimitedHttpCodes = [
        429,
        400 => [-1003],
    ];

    /**
     * Ignorable — no-ops / idempotent.
     *
     * 400:
     *  -4046: No need to change margin type
     *  -5027: No need to modify the order
     *  -2011: Unknown order sent
     */
    public $ignorableHttpCodes = [
        400 => [-4046, -5027, -2011],
    ];

    /**
     * Retryable — transient conditions.
     *
     * Notes:
     *  -1021 / -5028 => recvWindow/timestamp mismatch (we will clock-sync + retry).
     *  -2013 can appear under load (eventual consistency) -- Order doesn't exist.
     */
    public $retryableHttpCodes = [
        503,
        504,
        409,
        400 => [-1021, -5028, -2013],
        408 => [-1007],
        -2013,
    ];

    /**
     * recvWindow mismatches:
     * • -1021 (spot), -5028 (futures)
     */
    public $recvWindowMismatchedHttpCodes = [
        400 => [-1021, -5028],
    ];

    public function __construct()
    {
        // Base fallback when no Retry-After is available.
        $this->backoffSeconds = 10;
    }

    /**
     * Health check, kept simple.
     */
    public function ping(): bool
    {
        return true;
    }

    public function getApiSystem(): string
    {
        return 'binance';
    }

    /**
     * Case 1: IP not whitelisted by user.
     * User forgot to add server IP to their API key whitelist on Binance.
     * Detected by: HTTP 401 with -2015 AND message contains "IP".
     *
     * Recovery: User adds IP to exchange whitelist.
     */
    public function isIpNotWhitelisted(Throwable $exception): bool
    {
        if (! $this->containsHttpExceptionIn($exception, $this->ipNotWhitelistedHttpCodes)) {
            return false;
        }

        // Check if message mentions IP (distinguishes from API key issues)
        $data = $this->extractHttpErrorCodes($exception);
        $message = mb_strtolower((string) ($data['message'] ?? ''));

        return str_contains(haystack: $message, needle: 'ip');
    }

    /**
     * Case 2: IP temporarily rate-limited.
     * Server hit rate limits and is temporarily blocked.
     * Detected by: HTTP 418 WITH Retry-After header (or short duration).
     *
     * Recovery: Auto-recovers after Retry-After period.
     */
    public function isIpRateLimited(Throwable $exception): bool
    {
        if (! $exception instanceof RequestException || ! $exception->hasResponse()) {
            return false;
        }

        if ($exception->getResponse()->getStatusCode() !== 418) {
            return false;
        }

        // 418 with Retry-After = temporary rate limit
        $meta = $this->extractHttpMeta($exception);
        $retryAfter = mb_trim((string) ($meta['retry_after'] ?? ''));

        if ($retryAfter === '') {
            return false; // No Retry-After = permanent ban (Case 3)
        }

        // If Retry-After is very long (> 3 days = 259200 seconds), treat as permanent
        if (is_numeric($retryAfter) && (int) $retryAfter > 259200) {
            return false; // Permanent ban
        }

        return true; // Temporary rate limit
    }

    /**
     * Case 3: IP permanently banned.
     * Server is permanently banned from Binance for ALL accounts.
     * Detected by: HTTP 418 WITHOUT Retry-After or with very long duration.
     *
     * Recovery: Manual - contact exchange support.
     */
    public function isIpBanned(Throwable $exception): bool
    {
        if (! $exception instanceof RequestException || ! $exception->hasResponse()) {
            return false;
        }

        if ($exception->getResponse()->getStatusCode() !== 418) {
            return false;
        }

        // 418 WITHOUT Retry-After = permanent ban
        $meta = $this->extractHttpMeta($exception);
        $retryAfter = mb_trim((string) ($meta['retry_after'] ?? ''));

        if ($retryAfter === '') {
            return true; // No Retry-After = permanent ban
        }

        // If Retry-After is very long (> 3 days), treat as permanent
        if (is_numeric($retryAfter) && (int) $retryAfter > 259200) {
            return true; // Permanent ban
        }

        return false; // Temporary rate limit (Case 2)
    }

    /**
     * Case 4: Account blocked.
     * Specific account's API key is revoked, disabled, or has permission issues.
     * Detected by: HTTP 401 with -2015 AND message does NOT mention "IP".
     *
     * Recovery: User regenerates API key on exchange.
     */
    public function isAccountBlocked(Throwable $exception): bool
    {
        if (! $this->containsHttpExceptionIn($exception, $this->accountBlockedHttpCodes)) {
            return false;
        }

        // Check if message does NOT mention IP (API key issue, not IP whitelist)
        $data = $this->extractHttpErrorCodes($exception);
        $message = mb_strtolower((string) ($data['message'] ?? ''));

        // If message contains "IP", it's Case 1 (IP not whitelisted), not Case 4
        return ! str_contains(haystack: $message, needle: 'ip');
    }

    /**
     * Override: compute a safe retry time using Binance headers when Retry-After is absent.
     *
     * Rules per Binance:
     * • If Retry-After is present (429/418), honor it.
     * • For 429 caused by Unfilled-Order-Count, Retry-After may be missing — back off to the
     *   next interval boundary (prefer the *shortest* advertised interval).
     * • If no interval headers are present, conservatively wait to the next minute boundary.
     */
    public function rateLimitUntil(RequestException $exception): Carbon
    {
        $now = Carbon::now();

        // 1) Use base logic first (honors Retry-After or falls back)
        $baseUntil = $this->baseRateLimitUntil($exception);

        // If parent already derived a future time (e.g., via Retry-After), keep it.
        if ($baseUntil->greaterThan($now)) {
            return $baseUntil;
        }

        // 2) No usable Retry-After: examine Binance interval headers
        $meta = $this->extractHttpMeta($exception);
        $headers = $meta['headers'] ?? [];

        // Parse intervalized headers for both weight and order count
        $usedWeight = $this->parseIntervalHeaders($headers, 'x-mbx-used-weight-');
        $orderCount = $this->parseIntervalHeaders($headers, 'x-mbx-order-count-');

        // Choose the shortest available interval among both families
        $pick = static function (array $arr): ?array {
            if ($arr === []) {
                return null;
            }
            // Order of magnitude: s < m < h < d; then smaller intervalNum first
            $rank = ['s' => 1, 'm' => 2, 'h' => 3, 'd' => 4];
            uasort($arr, static function ($a, $b) use ($rank) {
                $la = $rank[$a['intervalLetter']] ?? 99;
                $lb = $rank[$b['intervalLetter']] ?? 99;
                if ($la === $lb) {
                    return $a['intervalNum'] <=> $b['intervalNum'];
                }

                return $la <=> $lb;
            });

            return reset($arr) ?: null;
        };

        $candidate = $pick($orderCount) ?? $pick($usedWeight);

        // Compute next window reset from server Date (if present) or local now.
        $serverNow = isset($meta['server_date']) ? Carbon::parse($meta['server_date']) : $now;

        if ($candidate) {
            $resetAt = $this->nextWindowResetAt(
                $serverNow,
                (int) $candidate['intervalNum'],
                (string) $candidate['intervalLetter']
            );

            // Tiny jitter to avoid stampede at the exact boundary
            return $resetAt->copy()->addMilliseconds(random_int(20, 80));
        }

        // 3) Last resort: next minute boundary (safe default for 429 without headers)
        return $serverNow->copy()->startOfMinute()->addMinute()->addMilliseconds(random_int(20, 80));
    }

    /**
     * Override: slightly escalate default when 418 (ban) and Retry-After absent.
     */
    public function backoffSeconds(Throwable $e): int
    {
        if ($e instanceof RequestException && $e->hasResponse()) {
            if ($this->isRateLimited($e) || in_array($e->getResponse()->getStatusCode(), [429, 418], strict: true)) {
                $until = $this->rateLimitUntil($e);
                $delta = max(0, now()->diffInSeconds($until, false));

                if ($this->isIpBanned($e)) {
                    // Be extra conservative on bans if no Retry-After was provided.
                    return (int) max($delta, $this->backoffSeconds * 3);
                }

                return (int) max($delta, $this->backoffSeconds);
            }
        }

        return $this->backoffSeconds;
    }

    /**
     * Record response headers for rate limiting coordination.
     * Delegates to BinanceThrottler to parse and cache rate limit headers.
     * Passes account ID for per-account ORDER limit tracking.
     */
    public function recordResponseHeaders(ResponseInterface $response): void
    {
        $accountId = $this->account?->id;
        BinanceThrottler::recordResponseHeaders($response, $accountId);
    }

    /**
     * Check if current server IP is banned by Binance.
     * Delegates to BinanceThrottler which tracks IP bans in cache.
     */
    public function isCurrentlyBanned(): bool
    {
        return BinanceThrottler::isCurrentlyBanned();
    }

    /**
     * Record an IP ban when 418/429 errors with Retry-After occur.
     * Delegates to BinanceThrottler to store ban state in cache.
     */
    public function recordIpBan(int $retryAfterSeconds): void
    {
        BinanceThrottler::recordIpBan($retryAfterSeconds);
    }

    /**
     * Pre-flight safety check before making API request.
     * Checks: IP ban status, rate limit proximity (>80%).
     * Delegates to BinanceThrottler.
     */
    public function isSafeToMakeRequest(): bool
    {
        // If IP is banned or approaching rate limits, return false
        return BinanceThrottler::isSafeToDispatch() === 0;
    }

    /**
     * Parse Binance interval headers (e.g., X-MBX-USED-WEIGHT-1M, X-MBX-ORDER-COUNT-10S).
     * Used internally by rateLimitUntil() to calculate retry times.
     *
     * @param  array  $headers  Normalized headers (lowercase keys)
     * @param  string  $prefix  Header prefix to match (e.g., 'x-mbx-used-weight-')
     * @return array Array of ['intervalNum' => int, 'intervalLetter' => string, 'value' => int, 'interval' => string]
     */
    protected function parseIntervalHeaders(array $headers, string $prefix): array
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
}
