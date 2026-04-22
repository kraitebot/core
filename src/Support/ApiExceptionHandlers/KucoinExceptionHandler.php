<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiExceptionHandlers;

use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Carbon;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Concerns\ApiExceptionHelpers;
use Kraite\Core\Support\Throttlers\KucoinThrottler;
use Psr\Http\Message\ResponseInterface;
use Throwable;

final class KucoinExceptionHandler extends BaseExceptionHandler
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
     * KuCoin uses HTTP 200 with "code" field for some edge cases.
     */
    public array $ignorableHttpCodes = [];

    /**
     * Errors that can be retried (transient issues).
     * Includes standard HTTP errors and KuCoin-specific responses.
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
     * KuCoin may return 403 for IP whitelist issues.
     *
     * @var array<int, array<int, int>|int>
     */
    public array $ipNotWhitelistedHttpCodes = [];

    /**
     * IP temporarily rate-limited (auto-recovers).
     * HTTP 429: Too many requests
     *
     * KuCoin error codes that indicate rate limiting:
     * - 429000: Too many requests
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
     * KuCoin error codes:
     * - 400100: Invalid API key
     * - 411100: User is frozen
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
     * KuCoin validates timestamps on signed requests.
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
     * Detected by: HTTP 429 or KuCoin code 429000.
     *
     * Recovery: Auto-recovers after Retry-After period.
     */
    public function isIpRateLimited(Throwable $exception): bool
    {
        // Check HTTP 429
        if ($this->containsHttpExceptionIn($exception, $this->ipRateLimitedHttpCodes)) {
            return true;
        }

        // Check KuCoin-specific rate limit code
        if ($exception instanceof RequestException && $exception->hasResponse()) {
            $body = (string) $exception->getResponse()->getBody();
            $json = json_decode($body, associative: true);

            if (is_array($json) && isset($json['code'])) {
                // KuCoin rate limit error code
                if ($json['code'] === '429000') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Case 4: Account blocked.
     * Specific account's API key is revoked, disabled, or has permission issues.
     * Detected by: HTTP 401 or KuCoin codes 400100, 411100.
     *
     * Recovery: User regenerates API key on exchange.
     */
    public function isAccountBlocked(Throwable $exception): bool
    {
        // Check HTTP 401
        if ($this->containsHttpExceptionIn($exception, $this->accountBlockedHttpCodes)) {
            return true;
        }

        // Check KuCoin-specific account blocked codes
        if ($exception instanceof RequestException && $exception->hasResponse()) {
            $body = (string) $exception->getResponse()->getBody();
            $json = json_decode($body, associative: true);

            if (is_array($json) && isset($json['code'])) {
                // 400100: Invalid API key
                // 411100: User is frozen
                if (in_array($json['code'], ['400100', '411100'], strict: true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Calculate when to retry after rate limit.
     * Override: compute a safe retry time using KuCoin headers when Retry-After is absent.
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

        // 2) KuCoin Futures uses 3-second windows for rate limiting
        // Public: 30 requests/3s, Private: 75 requests/3s
        // If we hit 429, wait until next window boundary + jitter
        return Carbon::now()->addSeconds(3 + random_int(1, 2));
    }

    /**
     * Override: calculate backoff for rate limits considering KuCoin headers.
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
     * Includes KuCoin-specific retryable codes.
     */
    public function retryException(Throwable $exception): bool
    {
        // Check standard HTTP retryable codes
        if ($this->containsHttpExceptionIn($exception, $this->retryableHttpCodes)) {
            return true;
        }

        // Check KuCoin-specific retryable codes
        if ($exception instanceof RequestException && $exception->hasResponse()) {
            $body = (string) $exception->getResponse()->getBody();
            $json = json_decode($body, associative: true);

            if (is_array($json) && isset($json['code'])) {
                // 200004: Order not exist (eventual consistency during high load)
                // 300000: Internal error (retryable)
                if (in_array($json['code'], ['200004', '300000'], strict: true)) {
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
     * Ping the KuCoin API to check connectivity.
     */
    public function ping(): bool
    {
        return true;
    }

    public function getApiSystem(): string
    {
        return 'kucoin';
    }

    /**
     * Extract KuCoin error codes from response body.
     * KuCoin uses: {"code": "200004", "msg": "Order not exist"}
     *
     * Common KuCoin error codes:
     * - 200000: Success
     * - 200001: Order creation limit
     * - 200002: Order amount limit
     * - 200003: Insufficient balance
     * - 200004: Order not exist
     * - 300000: Internal error (retryable)
     * - 400001: Invalid parameter
     * - 400002: Invalid request
     * - 400003: Empty request
     * - 400100: Invalid API key
     * - 411100: User is frozen
     * - 429000: Too many requests
     */
    public function extractHttpErrorCodes(Throwable|ResponseInterface $input): array
    {
        $data = $this->baseExtractHttpErrorCodes($input);

        // KuCoin uses "code" and "msg" fields
        if ($input instanceof RequestException && $input->hasResponse()) {
            $body = (string) $input->getResponse()->getBody();
            $json = json_decode($body, associative: true);

            if (is_array($json)) {
                // Extract KuCoin error code and message
                if (isset($json['code']) && $json['code'] !== '200000') {
                    $data['api_code'] = $json['code'];
                    $data['message'] = $json['msg'] ?? $data['message'];
                }
            }
        }

        return $data;
    }

    /**
     * Symbol removed / delisted by KuCoin.
     * Detected by vendor code 200003 ("The symbol parameter is invalid.").
     *
     * KuCoin emits 200003 on futures endpoints when the requested symbol
     * has been retired or never existed. Codes are delivered as strings.
     */
    public function isSymbolDelisted(Throwable $exception): bool
    {
        if (! $exception instanceof RequestException || ! $exception->hasResponse()) {
            return false;
        }

        $data = $this->extractHttpErrorCodes($exception);
        $code = (string) ($data['api_code'] ?? $data['status_code'] ?? '');

        return $code === '200003';
    }

    /**
     * Record response headers for IP-based rate limiting coordination.
     * Delegates to KucoinThrottler to record headers in cache.
     */
    public function recordResponseHeaders(ResponseInterface $response): void
    {
        KucoinThrottler::recordResponseHeaders($response);
    }

    /**
     * Check if current server IP is banned by KuCoin.
     * Delegates to KucoinThrottler which tracks IP bans in cache.
     */
    public function isCurrentlyBanned(): bool
    {
        return KucoinThrottler::isCurrentlyBanned();
    }

    /**
     * Record an IP ban when 429 errors occur.
     * Delegates to KucoinThrottler to store ban state in cache.
     */
    public function recordIpBan(int $retryAfterSeconds): void
    {
        KucoinThrottler::recordIpBan($retryAfterSeconds);
    }

    /**
     * Pre-flight safety check before making API request.
     * Checks: IP ban status.
     * Delegates to KucoinThrottler.
     */
    public function isSafeToMakeRequest(): bool
    {
        // If IP is banned, return false
        return KucoinThrottler::isSafeToDispatch() === 0;
    }
}
