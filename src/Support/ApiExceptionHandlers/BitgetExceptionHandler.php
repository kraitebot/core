<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiExceptionHandlers;

use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Client\RequestException as LaravelRequestException;
use Illuminate\Support\Carbon;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Concerns\ApiExceptionHelpers;
use Kraite\Core\Support\Throttlers\BitgetThrottler;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

final class BitgetExceptionHandler extends BaseExceptionHandler
{
    use ApiExceptionHelpers {
        extractHttpErrorCodes as protected baseExtractHttpErrorCodes;
        rateLimitUntil as baseRateLimitUntil;
    }

    private const array ACCOUNT_BLOCKED_CODES = [
        '40006',
        '40009',
        '40011',
        '40012',
        '40014',
        '40025',
        '40036',
        '40037',
        '40040',
        '40041',
    ];

    private const array IP_NOT_WHITELISTED_CODES = ['40018', '40038'];

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
     *
     * BitGet uses HTTP 200 with a `code` field carrying a STRING vendor
     * code (unlike Binance/Bybit which return ints). The codes here use
     * the string form so the base `containsHttpExceptionIn` strict
     * comparison matches.
     *
     * 200 / "22001": "no order to cancel" — the cancel target was
     *   already removed (filled, manually cancelled, expired). The
     *   drift spotter's orphan-cleanup path needs this classified as
     *   ignorable so a stale orphan cancel completes cleanly.
     * 200 / "43001": "Order does not exist" — sibling code returned by
     *   certain plan-order cancel paths.
     */
    public array $ignorableHttpCodes = [
        200 => ['22001', '43001'],
    ];

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
     * 40018: Invalid IP.
     * 40038: Current IP is not bound to the API key.
     *
     * @var array<int, array<int, string>|int>
     */
    public array $ipNotWhitelistedHttpCodes = [
        200 => self::IP_NOT_WHITELISTED_CODES,
    ];

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
     * Current Bitget credential and permission failures. Generic parameter
     * errors and IP-whitelist failures are intentionally excluded.
     *
     * @var array<int, array<int, string>|int>
     */
    public array $accountBlockedHttpCodes = [
        401,
        200 => self::ACCOUNT_BLOCKED_CODES,
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
        // Plain HTTP 429 alone is NOT enough to declare a fleet-wide IP
        // ban. Bitget routinely emits soft 429s for short bursts that
        // recover within the next request window — treating those as
        // an IP-level ban writes a `ForbiddenHostname` row that blocks
        // every account on this exchange/IP, synchronising the entire
        // fleet into a coordinated pause. The generic `isRateLimited()`
        // path handles soft 429s correctly via `rescheduleWithoutRetry()`
        // without polluting fleet-wide state. Require explicit ban
        // evidence (a `Retry-After` header — only the exchange knows the
        // ban window) before classifying as IP-rate-limited.
        if (! $this->containsHttpExceptionIn($exception, $this->ipRateLimitedHttpCodes)) {
            return false;
        }

        if ($exception instanceof RequestException && $exception->hasResponse()) {
            $response = $exception->getResponse();

            if ($response !== null && $response->hasHeader('Retry-After')) {
                return true;
            }
        }

        return false;
    }

    public function isIpNotWhitelisted(Throwable $exception): bool
    {
        return $this->containsBitgetVendorCode($exception, self::IP_NOT_WHITELISTED_CODES);
    }

    /**
     * Case 4: Account blocked.
     * Specific account's API key is revoked, disabled, or has permission issues.
     * Detected by: HTTP 401 or current Bitget credential/permission codes.
     *
     * Recovery: User regenerates API key on exchange.
     */
    public function isAccountBlocked(Throwable $exception): bool
    {
        return $this->containsHttpExceptionIn($exception, [401])
            || $this->containsBitgetVendorCode($exception, self::ACCOUNT_BLOCKED_CODES);
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

        // Bitget directs clients that receive 429 to stop requests for five
        // minutes before resuming when no explicit Retry-After is supplied.
        return Carbon::now()->addMinutes(5);
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
     * Symbol removed / delisted by BitGet.
     *
     * Detected by two vendor codes (BitGet delivers codes as strings inside
     * the JSON body):
     *  - 40309 "The contract has been removed" — the explicit delist signal
     *    on the trading / contract endpoints.
     *  - 40034 "Parameter {symbol} does not exist" — the kline / market-data
     *    endpoints answer with this once a contract is gone from the
     *    platform. Seen live during the 2026-06 TON→GRAM rebrand: BitGet
     *    pulled the TONUSDT contract and every kline fetch returned 40034,
     *    so the reactive self-heal never fired and the dead symbol failed
     *    each refresh cycle until the slower proactive sweep caught it.
     *
     * 40034 is safe to treat as a delisting because it is distinct from
     * 40808 "Parameter verification exception" (a malformed / invalid
     * parameter such as a bad granularity): a request-shape bug surfaces as
     * 40808, never 40034. 40034 means the looked-up symbol itself is not
     * found, which on these read-only endpoints can only mean it is gone.
     */
    public function isSymbolDelisted(Throwable $exception): bool
    {
        $hasResponse = ($exception instanceof RequestException && $exception->hasResponse())
            || $exception instanceof LaravelRequestException;

        if (! $hasResponse) {
            return false;
        }

        $data = $this->extractHttpErrorCodes($exception);
        $code = (string) ($data['api_code'] ?? $data['status_code'] ?? '');

        return in_array($code, ['40309', '40034'], strict: true);
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
        if ($input instanceof LaravelRequestException) {
            $json = $input->response->json();
            $data['http_code'] = $input->response->status();
        } elseif ($input instanceof RequestException && $input->hasResponse()) {
            $body = (string) $input->getResponse()->getBody();
            $json = json_decode($body, associative: true);
        } else {
            $json = null;
        }

        if (is_array($json)) {
            // Extract BitGet error code and message
            if (isset($json['code']) && $json['code'] !== '00000') {
                $data['api_code'] = $json['code'];
                $data['message'] = $json['msg'] ?? $data['message'];
            }
        }

        return $data;
    }

    public function shouldThrowExceptionFromHTTP200(ResponseInterface $response, RequestInterface $request): void
    {
        $payload = json_decode((string) $response->getBody(), associative: true);

        if (
            ! is_array($payload)
            || ! array_key_exists('code', $payload)
            || (! is_string($payload['code']) && ! is_int($payload['code']))
        ) {
            $response->getBody()->rewind();

            throw new RequestException(
                'Bitget API error: malformed HTTP 200 response envelope',
                $request,
                $response
            );
        }

        $code = (string) $payload['code'];

        if ($code === '00000') {
            return;
        }

        $rawMessage = $payload['msg'] ?? null;
        $message = is_string($rawMessage) ? $rawMessage : 'Unknown Bitget API error';
        $response->getBody()->rewind();

        throw new RequestException(
            "Bitget API error (code {$code}): {$message}",
            $request,
            $response
        );
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

    /** @param array<int, string> $codes */
    private function containsBitgetVendorCode(Throwable $exception, array $codes): bool
    {
        $current = $exception;

        do {
            $error = $this->extractHttpErrorCodes($current);
            $vendorCode = $error['status_code'] ?? null;

            if (is_string($vendorCode) && in_array($vendorCode, $codes, strict: true)) {
                return true;
            }

            $current = $current->getPrevious();
        } while ($current instanceof Throwable);

        return false;
    }
}
