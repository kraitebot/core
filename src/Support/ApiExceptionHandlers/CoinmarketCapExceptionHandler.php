<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiExceptionHandlers;

use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Carbon;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Concerns\ApiExceptionHelpers;
use Throwable;

/**
 * CoinmarketCapExceptionHandler
 *
 * • Rate limit is primarily per-minute (HTTP 429). Some 429 codes represent daily/monthly/IP limits.
 * • Prefer Retry-After if present. If missing, derive the next boundary from server Date:
 *      - 1008 [MINUTE_RATE_LIMIT] or 1011 [IP_RATE_LIMIT]  => next minute boundary
 *      - 1009 [DAILY_RATE_LIMIT]                           => start of next day
 *      - 1010 [MONTHLY_RATE_LIMIT]                         => start of next month
 * • 401/402/403 are true forbidden states (plan/authorization).
 * • 500/502/503/504/408 treated as retryable (transient).
 */
final class CoinmarketCapExceptionHandler extends BaseExceptionHandler
{
    use ApiExceptionHelpers {
        rateLimitUntil as baseRateLimitUntil;
    }

    /**
     * Ignorable: Invalid symbol names or malformed requests that should not retry.
     * 400 = Bad Request (invalid symbol, malformed parameters, etc.)
     */
    public array $ignorableHttpCodes = [400];

    /**
     * Retryable: transient server-side/network conditions.
     * Standard HTTP errors that can be retried.
     */
    public array $retryableHttpCodes = [
        408,     // Request timeout
        500,     // Internal server error
        502,     // Bad gateway
        503,     // Service unavailable
        504,     // Gateway timeout
    ];

    /**
     * Server forbidden: authentication/plan/permission issues (server cannot make ANY calls).
     * Map explicit vendor error codes for clarity.
     *
     * NOTE: For CoinMarketCap, all forbidden errors are account-specific (API key issues).
     * Use isAccountBlocked() for specific classification.
     */
    public array $serverForbiddenHttpCodes = [
        401 => [1001, 1002],       // invalid/missing API key
        402 => [1003, 1004],       // plan requires payment / payment expired
        403 => [1005, 1006, 1007], // key required / plan not authorized / key disabled
        // (If vendor code not present, we still treat 401/402/403 as forbidden.)
    ];

    /**
     * Account blocked — API key issues, payment issues, or permission issues.
     * These are all account-specific issues that require user action.
     *
     * @var array<int, array<int, int>|int>
     */
    public array $accountBlockedHttpCodes = [
        401 => [1001, 1002],       // invalid/missing API key
        402 => [1003, 1004],       // plan requires payment / payment expired
        403 => [1005, 1006, 1007], // key required / plan not authorized / key disabled
    ];

    /**
     * Rate-limited: primary signal.
     */
    public array $serverRateLimitedHttpCodes = [429];

    /**
     * RecvWindow mismatches: not applicable for CMC (keep empty).
     */
    public array $recvWindowMismatchedHttpCodes = [];

    /**
     * Vendor codes for rate limit *types* under HTTP 429.
     */
    public array $cmcMinuteCodes = [1008, 1011]; // minute rate limit reached, IP rate limit reached

    public array $cmcDailyCodes = [1009];       // daily cap

    public array $cmcMonthlyCodes = [1010];       // monthly cap

    public function __construct()
    {
        // Base fallback when no Retry-After is present.
        $this->backoffSeconds = 30;
    }

    /**
     * Ping the CoinMarketCap API to check connectivity.
     */
    public function ping(): bool
    {
        // CoinMarketCap doesn't have a dedicated ping endpoint
        // Return true as we'll discover issues through actual API calls
        return true;
    }

    public function getApiSystem(): string
    {
        return 'coinmarketcap';
    }

    /**
     * Case 4: Account blocked.
     * For CoinMarketCap, this includes API key issues (401), payment issues (402), and permission issues (403).
     * All require user action to resolve (check API key, renew subscription, etc.).
     */
    public function isAccountBlocked(Throwable $exception): bool
    {
        return $this->containsHttpExceptionIn($exception, $this->accountBlockedHttpCodes);
    }

    /**
     * Compute a safe retry time:
     *  • If Retry-After exists, honor it.
     *  • Else, align to the boundary indicated by vendor code.
     *  • Else, fall back to base backoff.
     */
    public function rateLimitUntil(RequestException $exception): Carbon
    {
        $now = Carbon::now();

        // 1) Generic logic first (honors Retry-After if present)
        $base = $this->baseRateLimitUntil($exception);
        if ($base->greaterThan($now)) {
            return $base;
        }

        // 2) Derive from vendor code (nested CMC "status.error_code" supported by trait)
        $meta = $this->extractHttpMeta($exception);
        $vendorCode = (int) ($meta['status_code'] ?? 0);

        $serverNow = isset($meta['server_date'])
            ? Carbon::parse($meta['server_date'])
            : $now;

        // Minute-level caps (reset every 60s)
        if (in_array($vendorCode, $this->cmcMinuteCodes, strict: true)) {
            $resetAt = $this->nextWindowResetAt($serverNow, 1, 'm');

            return $resetAt->copy()->addMilliseconds(random_int(20, 80));
        }

        // Daily cap: wait until start of next day
        if (in_array($vendorCode, $this->cmcDailyCodes, strict: true)) {
            return $serverNow->copy()->startOfDay()->addDay()->addSeconds(random_int(2, 6));
        }

        // Monthly cap: wait until start of next month
        if (in_array($vendorCode, $this->cmcMonthlyCodes, strict: true)) {
            return $serverNow->copy()->startOfMonth()->addMonth()->addSeconds(random_int(2, 6));
        }

        // 3) Unknown 429 flavor: conservative next-minute default
        return $serverNow->copy()->startOfMinute()->addMinute()->addMilliseconds(random_int(20, 80));
    }

    /**
     * Legacy seconds-based backoff for callers that expect an int.
     * Escalate for daily/monthly exhaustion.
     */
    public function backoffSeconds(Throwable $e): int
    {
        if ($e instanceof RequestException && $e->hasResponse() && $this->isRateLimited($e)) {
            $until = $this->rateLimitUntil($e);

            return (int) max(0, now()->diffInSeconds($until, false));
        }

        return $this->backoffSeconds;
    }
}
