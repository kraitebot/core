<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiExceptionHandlers;

use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Carbon;

use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Concerns\ApiExceptionHelpers;
use Kraite\Core\Support\Throttlers\BybitThrottler;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

final class BybitExceptionHandler extends BaseExceptionHandler
{
    use ApiExceptionHelpers {
        extractHttpErrorCodes as protected baseExtractHttpErrorCodes;
        rateLimitUntil as baseRateLimitUntil;
    }

    /**
     * Server instability — exchange is having infrastructure problems.
     * Triggers exchange-level cooldown to prevent opening new positions.
     *
     * HTTP 500/502/503/504: Standard server errors
     * 200/10000: Server Timeout
     * 200/10016: Internal server error or service restarting
     * 200/10019: Service restarting
     */
    public array $serverInstabilityHttpCodes = [
        500,
        502,
        503,
        504,
        200 => [10000, 10016, 10019],
    ];

    /**
     * Errors that should be ignored (no action needed).
     * HTTP 200 with ignorable retCodes in response body.
     */
    public array $ignorableHttpCodes = [
        200 => [
            20006,   // Duplicate request ID
            10014,   // Invalid duplicate request
            34040,   // Not modified (value already set TP/SL)
            110025,  // Position mode not modified
            110026,  // Cross/isolated margin mode unchanged
            110027,  // Margin unchanged
            110030,  // Duplicate order ID
            110043,  // Set leverage not modified
        ],
    ];

    /**
     * Errors that can be retried (transient issues).
     * Includes standard HTTP errors and Bybit-specific retCodes.
     */
    public array $retryableHttpCodes = [
        400,     // Bad request (transient)
        404,     // Cannot find path
        408,     // Request timeout
        500,     // Internal server error
        502,     // Bad gateway
        503,     // Service unavailable
        504,     // Gateway timeout
        200 => [
            10000,   // Server timeout
            10016,   // Internal server error or service restarting
            10019,   // Service restarting
            170007,  // Backend timeout
            170032,  // Network error
            170146,  // Order creation timeout
            170147,  // Order cancellation timeout
            170213,  // Order does not exist (eventual consistency during high load)
            177002,  // Server busy
            131200,  // Service error
            131201,  // Internal error
            131230,  // System busy
        ],
    ];

    /**
     * Server forbidden — real exchange-level bans and credential failures.
     * HTTP 401: Authentication failed
     * HTTP 200 with permanent ban/credential retCodes.
     *
     * NOTE: This array is used by the generic isForbidden() method.
     * For more specific classification, use:
     * - isIpNotWhitelisted(): User forgot to whitelist IP (10010)
     * - isIpRateLimited(): Temporary ban (10018, 403)
     * - isIpBanned(): Permanent ban (10009)
     * - isAccountBlocked(): API key issues (10003, 10004, 10005, 10007)
     */
    public array $serverForbiddenHttpCodes = [
        401,     // HTTP-level: Authentication failed
        200 => [
            10003,   // API key is invalid or domain mismatch
            10004,   // Invalid signature
            10005,   // Permission denied, check API key permissions
            10007,   // User authentication failed
            10009,   // IP banned by exchange (permanent)
            10010,   // Unmatched IP, check API key's bound IP addresses
        ],
    ];

    /**
     * IP not whitelisted by user on their API key settings.
     * HTTP 200 with 10010: "Unmatched IP, please check your API key's bound IP addresses"
     *
     * @var array<int, array<int, int>|int>
     */
    public array $ipNotWhitelistedHttpCodes = [
        200 => [10010],
    ];

    /**
     * IP temporarily rate-limited (auto-recovers).
     * HTTP 200 with 10018: "Exceeded the IP Rate Limit"
     * HTTP 403: IP rate limit breached (temporary, lifts after 10 minutes)
     *
     * @var array<int, array<int, int>|int>
     */
    public array $ipRateLimitedHttpCodes = [
        403,
        200 => [10018],
    ];

    /**
     * IP permanently banned by exchange.
     * HTTP 200 with 10009: "IP has been banned"
     *
     * @var array<int, array<int, int>|int>
     */
    public array $ipBannedHttpCodes = [
        200 => [10009],
    ];

    /**
     * Account blocked — API key revoked, disabled, or permission issues.
     * HTTP 401: Authentication failed at HTTP level
     * HTTP 200 with 10003: API key is invalid or domain mismatch
     * HTTP 200 with 10004: Invalid signature
     * HTTP 200 with 10005: Permission denied
     * HTTP 200 with 10007: User authentication failed
     *
     * @var array<int, array<int, int>|int>
     */
    public array $accountBlockedHttpCodes = [
        401,
        200 => [10003, 10004, 10005, 10007],
    ];

    /**
     * Rate limit related error codes.
     * • HTTP 200: Too many visits (per-UID limit), exceeded IP rate limit
     * • HTTP 403: IP rate limit breached (temporary, lifts after 10 minutes)
     * • HTTP 429: IP auto-banned for continuing after 429 codes
     */
    public array $serverRateLimitedHttpCodes = [
        403,     // HTTP-level: IP rate limit breached (temporary)
        429,     // HTTP-level: IP auto-banned
        200 => [
            10006,   // Too many visits (per-UID)
            10018,   // Exceeded IP rate limit
            170005,  // Exceeded max orders per time period
            170222,  // Too many requests
        ],
    ];

    /**
     * recvWindow mismatches: timestamp synchronization errors.
     * Bybit requires: server_time - recv_window <= timestamp < server_time + 1000
     */
    public array $recvWindowMismatchedHttpCodes = [
        200 => [
            10002,   // Invalid request, please check your timestamp or recv_window param
        ],
    ];

    public function __construct()
    {
        // Conservative backoff when no Retry-After is available
        $this->backoffSeconds = 10;
    }

    /**
     * Case 1: IP not whitelisted by user.
     * User forgot to add server IP to their API key whitelist on Bybit.
     * Detected by: HTTP 200 with retCode 10010.
     *
     * Recovery: User adds IP to exchange whitelist.
     */
    public function isIpNotWhitelisted(Throwable $exception): bool
    {
        return $this->containsHttpExceptionIn($exception, $this->ipNotWhitelistedHttpCodes);
    }

    /**
     * Case 2: IP temporarily rate-limited.
     * Server hit rate limits and is temporarily blocked.
     * Detected by: HTTP 403 or HTTP 200 with retCode 10018.
     *
     * Recovery: Auto-recovers after ~10 minutes.
     */
    public function isIpRateLimited(Throwable $exception): bool
    {
        return $this->containsHttpExceptionIn($exception, $this->ipRateLimitedHttpCodes);
    }

    /**
     * Case 3: IP permanently banned.
     * Server is permanently banned from Bybit for ALL accounts.
     * Detected by: HTTP 200 with retCode 10009 OR HTTP 429.
     *
     * Recovery: Manual - contact exchange support.
     */
    public function isIpBanned(Throwable $exception): bool
    {
        // Check for explicit permanent ban code
        if ($this->containsHttpExceptionIn($exception, $this->ipBannedHttpCodes)) {
            return true;
        }

        // HTTP 429 is also treated as permanent ban by Bybit
        if (! $exception instanceof RequestException || ! $exception->hasResponse()) {
            return false;
        }

        return $exception->getResponse()->getStatusCode() === 429;
    }

    /**
     * Case 4: Account blocked.
     * Specific account's API key is revoked, disabled, or has permission issues.
     * Detected by: HTTP 401 or HTTP 200 with retCodes 10003, 10004, 10005, 10007.
     *
     * Recovery: User regenerates API key on exchange.
     */
    public function isAccountBlocked(Throwable $exception): bool
    {
        return $this->containsHttpExceptionIn($exception, $this->accountBlockedHttpCodes);
    }

    /**
     * Calculate when to retry after rate limit.
     * Override: compute a safe retry time using Bybit headers when Retry-After is absent.
     *
     * Rules:
     * • If Retry-After is present, honor it (handled by base trait).
     * • Check for Bybit-specific X-Bapi-Limit-Reset-Timestamp header.
     * • Otherwise fall back to base backoff.
     */
    public function rateLimitUntil(RequestException $exception): Carbon
    {
        // 1) Check if there's a retry-after header first
        $meta = $this->extractHttpMeta($exception);
        $retryAfter = mb_trim((string) ($meta['retry_after'] ?? ''));

        // If Retry-After is present, use base logic
        if ($retryAfter !== '') {
            return $this->baseRateLimitUntil($exception);
        }

        // 2) No Retry-After: check Bybit-specific rate limit headers
        $headers = $meta['headers'] ?? [];

        // X-Bapi-Limit-Reset-Timestamp (millisecond timestamp when rate limit resets)
        if (isset($headers['x-bapi-limit-reset-timestamp'])) {
            $resetTimestamp = (int) $headers['x-bapi-limit-reset-timestamp'];
            if ($resetTimestamp > 0) {
                $resetAt = Carbon::createFromTimestampMs($resetTimestamp)->utc();

                // Add tiny jitter to avoid stampede at exact boundary
                return $resetAt->copy()->addMilliseconds(random_int(20, 80));
            }
        }

        // 3) Last resort: use the base backoff
        return $this->baseRateLimitUntil($exception);
    }

    /**
     * Override: calculate backoff for rate limits considering Bybit headers.
     * Escalates backoff for IP bans (HTTP 429).
     */
    public function backoffSeconds(Throwable $e): int
    {
        if ($e instanceof RequestException && $e->hasResponse()) {
            if ($this->isRateLimited($e) || in_array($e->getResponse()->getStatusCode(), [429, 403], strict: true)) {
                $until = $this->rateLimitUntil($e);
                $delta = max(0, now()->diffInSeconds($until, false));

                if ($this->isIpBanned($e)) {
                    // Be extra conservative on IP bans (10 minutes default)
                    // 3x multiplier ensures we wait well beyond the ban period
                    return (int) max($delta, $this->backoffSeconds * 3);
                }

                return (int) max($delta, $this->backoffSeconds);
            }
        }

        return $this->backoffSeconds;
    }

    /**
     * Check if exception should be retried.
     */
    public function retryException(Throwable $exception): bool
    {
        return $this->containsHttpExceptionIn($exception, $this->retryableHttpCodes);
    }

    /**
     * Check if exception should be ignored.
     */
    public function ignoreException(Throwable $exception): bool
    {
        return $this->containsHttpExceptionIn($exception, $this->ignorableHttpCodes);
    }

    /**
     * Symbol removed / delisted by Bybit.
     *
     * Bybit does not expose a dedicated "symbol delisted" retCode for
     * kline requests; it responds with HTTP 200 and retCode 10001 (the
     * generic parameter error) together with messages like
     * "Not supported symbols" or "symbol not exist".
     *
     * We therefore require both the retCode AND a descriptive message
     * phrase to avoid flagging unrelated parameter mistakes as a
     * delisting.
     */
    public function isSymbolDelisted(Throwable $exception): bool
    {
        if (! $exception instanceof RequestException || ! $exception->hasResponse()) {
            return false;
        }

        $data = $this->extractHttpErrorCodes($exception);

        if ((int) ($data['status_code'] ?? 0) !== 10001) {
            return false;
        }

        $message = mb_strtolower((string) ($data['message'] ?? ''));

        if ($message === '' || ! str_contains($message, 'symbol')) {
            return false;
        }

        return str_contains($message, 'not supported')
            || str_contains($message, 'not exist')
            || str_contains($message, 'invalid')
            || str_contains($message, 'delisted');
    }

    /**
     * Ping the Bybit API to check connectivity.
     */
    public function ping(): bool
    {
        // Bybit doesn't have a dedicated ping endpoint
        // We could use /v5/market/time but for now return true
        return true;
    }

    public function getApiSystem(): string
    {
        return 'bybit';
    }

    /**
     * Extract vendor error code from raw Bybit response.
     * Bybit uses 'retCode' instead of 'code'. A retCode of 0 means success.
     */
    public function extractVendorCodeFromResponse(?array $response): ?int
    {
        if ($response === null) {
            return null;
        }

        $retCode = $response['retCode'] ?? null;

        if ($retCode === null || (int) $retCode === 0) {
            return null;
        }

        return (int) $retCode;
    }

    /**
     * Extract Bybit error codes from response body.
     * Bybit uses {retCode, retMsg} structure.
     * Overrides parent to map Bybit's retCode to status_code.
     */
    public function extractHttpErrorCodes(Throwable|ResponseInterface $input): array
    {
        $data = $this->baseExtractHttpErrorCodes($input);

        // Parent extracts 'code' by default, but Bybit uses 'retCode'
        if ($input instanceof RequestException && $input->hasResponse()) {
            $body = (string) $input->getResponse()->getBody();
            $json = json_decode($body, associative: true);

            if (is_array($json) && isset($json['retCode'])) {
                $data['status_code'] = (int) $json['retCode'];
                $data['message'] = $json['retMsg'] ?? $data['message'];
            }
        }

        return $data;
    }

    /**
     * Check HTTP 200 responses for Bybit API-level errors and throw if found.
     * Bybit returns HTTP 200 even for errors, with retCode !== 0 indicating failure.
     * This converts such responses into RequestExceptions for normal error handling.
     *
     * @param  ResponseInterface  $response  The HTTP 200 response to check
     * @param  RequestInterface  $request  The original request (needed for RequestException)
     *
     * @throws RequestException  If retCode !== 0 (API-level error)
     */
    public function shouldThrowExceptionFromHTTP200(ResponseInterface $response, RequestInterface $request): void
    {
        $body = (string) $response->getBody();
        $json = json_decode($body, associative: true);

        if (! is_array($json)) {
            return;
        }

        $retCode = $json['retCode'] ?? 0;

        if ($retCode !== 0) {
            $retMsg = $json['retMsg'] ?? 'Unknown Bybit API error';

            // Rewind the response body so it can be read again by exception handlers
            $response->getBody()->rewind();

            throw new RequestException(
                "Bybit API error (code {$retCode}): {$retMsg}",
                $request,
                $response
            );
        }
    }

    /**
     * Record response headers for IP-based rate limiting coordination.
     * Delegates to BybitThrottler to record headers in cache.
     */
    public function recordResponseHeaders(ResponseInterface $response): void
    {
        BybitThrottler::recordResponseHeaders($response);
    }

    /**
     * Check if current server IP is banned by Bybit.
     * Delegates to BybitThrottler which tracks IP bans in cache.
     */
    public function isCurrentlyBanned(): bool
    {
        return BybitThrottler::isCurrentlyBanned();
    }

    /**
     * Record an IP ban when 429/403 errors occur.
     * Delegates to BybitThrottler to store ban state in cache.
     */
    public function recordIpBan(int $retryAfterSeconds): void
    {
        BybitThrottler::recordIpBan($retryAfterSeconds);
    }

    /**
     * Pre-flight safety check before making API request.
     * Checks: IP ban status.
     * Delegates to BybitThrottler.
     */
    public function isSafeToMakeRequest(): bool
    {
        // If IP is banned, return false
        return BybitThrottler::isSafeToDispatch() === 0;
    }
}
