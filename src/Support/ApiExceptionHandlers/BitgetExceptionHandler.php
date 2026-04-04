<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiExceptionHandlers;

use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Carbon;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Concerns\ApiExceptionHelpers;
use Kraite\Core\Support\Throttlers\BitgetThrottler;
use Psr\Http\Message\ResponseInterface;
use Throwable;

final class BitgetExceptionHandler extends BaseExceptionHandler
{
    use ApiExceptionHelpers {
        extractHttpErrorCodes as protected baseExtractHttpErrorCodes;
        rateLimitUntil as baseRateLimitUntil;
    }

    /**
     * Server instability — exchange is having infrastructure problems.
     * Triggers exchange-level cooldown to prevent opening new positions.
     *
     * 500: Internal Server Error
     * 502: Bad Gateway
     * 503: Service Unavailable (server overloaded)
     * 504: Gateway Timeout (upstream server timed out)
     */
    public array $serverInstabilityHttpCodes = [500, 502, 503, 504];

    /**
     * Errors that should be ignored (no action needed).
     * BitGet uses HTTP 200 with "code" field for some edge cases.
     */
    public array $ignorableHttpCodes = [];

    /**
     * Errors that can be retried (transient issues).
     * Includes standard HTTP errors and BitGet-specific responses.
     */
    public array $retryableHttpCodes = [
        408,     // Request timeout
        500,     // Internal server error
        502,     // Bad gateway
        503,     // Service unavailable
        504,     // Gateway timeout
    ];

    /**
     * Server forbidden — real exchange-level bans and credential failures.
     * HTTP 401: Authentication failed
     * HTTP 403: Forbidden (IP banned or permission issue)
     */
    public array $serverForbiddenHttpCodes = [
        401,     // Authentication failed
        403,     // Forbidden (may be IP ban or permission issue)
    ];

    /**
     * IP not whitelisted by user on their API key settings.
     * BitGet may return 403 for IP whitelist issues.
     *
     * @var array<int, array<int, int>|int>
     */
    public array $ipNotWhitelistedHttpCodes = [];

    /**
     * IP temporarily rate-limited (auto-recovers).
     * HTTP 429: Too many requests
     *
     * @var array<int, array<int, int>|int>
     */
    public array $ipRateLimitedHttpCodes = [
        429,
    ];

    /**
     * IP permanently banned by exchange.
     *
     * @var array<int, array<int, int>|int>
     */
    public array $ipBannedHttpCodes = [];

    /**
     * Account blocked — API key revoked, disabled, or permission issues.
     * HTTP 401: Authentication failed
     *
     * BitGet error codes:
     * - 40014: Invalid API key
     * - 40017: Parameter verification failed or not a trader
     * - 40018: Invalid passphrase
     *
     * @var array<int, array<int, int>|int>
     */
    public array $accountBlockedHttpCodes = [
        401,
    ];

    /**
     * Rate limit related error codes.
     * HTTP 429: Too many requests
     */
    public array $serverRateLimitedHttpCodes = [
        429,
    ];

    /**
     * recvWindow mismatches: timestamp synchronization errors.
     * BitGet validates timestamps on signed requests.
     */
    public array $recvWindowMismatchedHttpCodes = [];

    public function __construct()
    {
        // Conservative backoff when no Retry-After is available
        $this->backoffSeconds = 10;
    }

    /**
     * Case 2: IP temporarily rate-limited.
     * Server hit rate limits and is temporarily blocked.
     * Detected by: HTTP 429.
     *
     * Recovery: Auto-recovers after Retry-After period.
     */
    public function isIpRateLimited(Throwable $exception): bool
    {
        // Check HTTP 429
        if ($this->containsHttpExceptionIn($exception, $this->ipRateLimitedHttpCodes)) {
            return true;
        }

        return false;
    }

    /**
     * Case 4: Account blocked.
     * Specific account's API key is revoked, disabled, or has permission issues.
     * Detected by: HTTP 401 or BitGet codes 40014, 40017, 40018.
     *
     * Recovery: User regenerates API key on exchange.
     */
    public function isAccountBlocked(Throwable $exception): bool
    {
        // Check HTTP 401
        if ($this->containsHttpExceptionIn($exception, $this->accountBlockedHttpCodes)) {
            return true;
        }

        // Check BitGet-specific account blocked codes
        if ($exception instanceof RequestException && $exception->hasResponse()) {
            $body = (string) $exception->getResponse()->getBody();
            $json = json_decode($body, associative: true);

            if (is_array($json) && isset($json['code'])) {
                // 40009: Sign signature error (invalid secret)
                // 40014: Invalid API key
                // 40017: Parameter verification failed or not a trader
                // 40018: Invalid passphrase
                // 40037: API key does not exist
                if (in_array($json['code'], ['40009', '40014', '40017', '40018', '40037'], strict: true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Calculate when to retry after rate limit.
     * Override: compute a safe retry time using BitGet headers when Retry-After is absent.
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

        // 2) BitGet uses per-minute rate limits (6000 req/min/IP)
        // If we hit 429, wait for a window reset + jitter
        return Carbon::now()->addSeconds(5 + random_int(1, 3));
    }

    /**
     * Override: calculate backoff for rate limits considering BitGet headers.
     */
    public function backoffSeconds(Throwable $e): int
    {
        if ($e instanceof RequestException && $e->hasResponse()) {
            if ($this->isRateLimited($e) || $e->getResponse()->getStatusCode() === 429) {
                $until = $this->rateLimitUntil($e);
                $delta = max(0, now()->diffInSeconds($until, false));

                return (int) max($delta, $this->backoffSeconds);
            }
        }

        return $this->backoffSeconds;
    }

    /**
     * Check if exception should be retried.
     * Includes BitGet-specific retryable codes.
     */
    public function retryException(Throwable $exception): bool
    {
        // Check standard HTTP retryable codes
        if ($this->containsHttpExceptionIn($exception, $this->retryableHttpCodes)) {
            return true;
        }

        // Check BitGet-specific retryable codes
        if ($exception instanceof RequestException && $exception->hasResponse()) {
            $body = (string) $exception->getResponse()->getBody();
            $json = json_decode($body, associative: true);

            if (is_array($json) && isset($json['code'])) {
                // 40109: Order cannot be found (eventual consistency during high load)
                // 45001: System maintenance
                // 40725: System release error
                // 40015: System release error
                if (in_array($json['code'], ['40109', '45001', '40725', '40015'], strict: true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if exception should be ignored.
     */
    public function ignoreException(Throwable $exception): bool
    {
        return $this->containsHttpExceptionIn($exception, $this->ignorableHttpCodes);
    }

    /**
     * Ping the BitGet API to check connectivity.
     */
    public function ping(): bool
    {
        return true;
    }

    public function getApiSystem(): string
    {
        return 'bitget';
    }

    /**
     * Extract BitGet error codes from response body.
     * BitGet uses: {"code": "40808", "msg": "Parameter verification exception", "requestTime": ...}
     *
     * Common BitGet error codes:
     * - 00000: Success
     * - 40014: Invalid API key
     * - 40017: Parameter verification failed or not a trader
     * - 40018: Invalid passphrase
     * - 40808: Parameter verification exception
     * - 45001: System maintenance
     * - 40725: System release error
     * - 40015: System release error
     */
    public function extractHttpErrorCodes(Throwable|ResponseInterface $input): array
    {
        $data = $this->baseExtractHttpErrorCodes($input);

        // BitGet uses "code" and "msg" fields
        if ($input instanceof RequestException && $input->hasResponse()) {
            $body = (string) $input->getResponse()->getBody();
            $json = json_decode($body, associative: true);

            if (is_array($json)) {
                // Extract BitGet error code and message
                if (isset($json['code']) && $json['code'] !== '00000') {
                    $data['api_code'] = $json['code'];
                    $data['message'] = $json['msg'] ?? $data['message'];
                }
            }
        }

        return $data;
    }

    /**
     * Record response headers for IP-based rate limiting coordination.
     * Delegates to BitgetThrottler to record headers in cache.
     */
    public function recordResponseHeaders(ResponseInterface $response): void
    {
        BitgetThrottler::recordResponseHeaders($response);
    }

    /**
     * Check if current server IP is banned by BitGet.
     * Delegates to BitgetThrottler which tracks IP bans in cache.
     */
    public function isCurrentlyBanned(): bool
    {
        return BitgetThrottler::isCurrentlyBanned();
    }

    /**
     * Record an IP ban when 429 errors occur.
     * Delegates to BitgetThrottler to store ban state in cache.
     */
    public function recordIpBan(int $retryAfterSeconds): void
    {
        BitgetThrottler::recordIpBan($retryAfterSeconds);
    }

    /**
     * Pre-flight safety check before making API request.
     * Checks: IP ban status.
     * Delegates to BitgetThrottler.
     */
    public function isSafeToMakeRequest(): bool
    {
        // If IP is banned, return false
        return BitgetThrottler::isSafeToDispatch() === 0;
    }
}
