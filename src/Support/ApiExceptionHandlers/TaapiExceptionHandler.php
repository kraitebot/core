<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiExceptionHandlers;

use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Carbon;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Concerns\ApiExceptionHelpers;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * TaapiExceptionHandler
 *
 * Focus:
 * • Taapi.io uses 15-second rate limit windows per plan tier
 * • Returns 429 when rate limit exceeded with JSON error message
 * • No Retry-After or custom rate limit headers documented
 * • Default to 15-second window boundary on rate limit
 */
final class TaapiExceptionHandler extends BaseExceptionHandler
{
    use ApiExceptionHelpers {
        extractHttpErrorCodes as protected baseExtractHttpErrorCodes;
        rateLimitUntil as baseRateLimitUntil;
        ignoreException as baseIgnoreException;
    }

    /**
     * Ignorable — no-ops / idempotent.
     * 400: Bad request (malformed parameters, invalid data)
     *
     * @var list<int>
     */
    public array $ignorableHttpCodes = [
        400,
    ];

    /**
     * Retryable — transient conditions.
     * HTTP-level errors that can be retried.
     *
     * @var list<int>
     */
    public array $retryableHttpCodes = [
        500,     // Internal server error
        502,     // Bad gateway
        503,     // Service unavailable
        504,     // Gateway timeout
        408,     // Request timeout
    ];

    /**
     * Server forbidden — authentication/authorization failures (server cannot make ANY calls).
     * 401: Unauthorized (invalid/missing API key)
     * 402: Payment required (subscription expired)
     * 403: Forbidden (insufficient permissions)
     *
     * NOTE: For Taapi, all forbidden errors are account-specific (API key issues).
     * Use isAccountBlocked() for specific classification.
     *
     * @var list<int>
     */
    public array $serverForbiddenHttpCodes = [
        401,
        402,
        403,
    ];

    /**
     * Account blocked — API key invalid, expired, or insufficient permissions.
     * These are all account-specific issues that require user action.
     *
     * @var array<int, array<int, int>|int>
     */
    public array $accountBlockedHttpCodes = [
        401,     // Unauthorized (invalid/missing API key)
        402,     // Payment required (subscription expired)
        403,     // Forbidden (insufficient permissions)
    ];

    /**
     * Rate-limited — exceeded request quota.
     * 429: Too Many Requests (exceeded plan's request limit)
     *
     * @var list<int>
     */
    public array $serverRateLimitedHttpCodes = [
        429,
    ];

    /**
     * recvWindow mismatches: not applicable for Taapi.io.
     *
     * @var list<int>
     */
    public array $recvWindowMismatchedHttpCodes = [];

    /**
     * Error messages that should NOT be ignored even if HTTP 400.
     * These indicate configuration/plan errors rather than invalid input data.
     *
     * @var string[]
     */
    private array $nonIgnorableErrorPatterns = [
        'constructs than your plan allows',  // Plan limit exceeded
        'calculations than your plan allows', // Plan limit exceeded (alternate wording)
    ];

    public function __construct()
    {
        // Conservative backoff when no rate limit info available
        $this->backoffSeconds = 5;
    }

    /**
     * Ping the Taapi API to check connectivity.
     */
    public function ping(): bool
    {
        // Taapi doesn't have a dedicated ping endpoint
        // Return true as we'll discover issues through actual API calls
        return true;
    }

    public function getApiSystem(): string
    {
        return 'taapi';
    }

    /**
     * Support both legacy TAAPI errors and v2's
     * {statusCode, error, message} envelope.
     *
     * @return array{http_code: int|null, status_code: int|string|null, message: string|null}
     */
    public function extractHttpErrorCodes(Throwable|ResponseInterface $input): array
    {
        $data = $this->baseExtractHttpErrorCodes($input);
        $httpCode = is_int($data['http_code'] ?? null) ? $data['http_code'] : null;
        $statusCode = is_int($data['status_code'] ?? null) || is_string($data['status_code'] ?? null)
            ? $data['status_code']
            : null;
        $message = is_string($data['message'] ?? null) ? $data['message'] : null;
        $response = $input instanceof ResponseInterface
            ? $input
            : ($input instanceof RequestException && $input->hasResponse() ? $input->getResponse() : null);

        if ($response !== null) {
            $json = json_decode((string) $response->getBody(), true);
            if (is_array($json)) {
                $v2StatusCode = $json['statusCode'] ?? null;
                if (is_int($v2StatusCode) || is_string($v2StatusCode)) {
                    $statusCode = $v2StatusCode;
                }

                if (is_string($json['message'] ?? null)) {
                    $message = $json['message'];
                } elseif (is_string($json['error'] ?? null)) {
                    $message = $json['error'];
                }
            }
        }

        return [
            'http_code' => $httpCode,
            'status_code' => $statusCode,
            'message' => $message,
        ];
    }

    /**
     * Case 4: Account blocked.
     * For Taapi, this includes API key issues (401), payment issues (402), and permission issues (403).
     * All require user action to resolve (check API key, renew subscription, etc.).
     */
    public function isAccountBlocked(Throwable $exception): bool
    {
        return $this->containsHttpExceptionIn($exception, $this->accountBlockedHttpCodes);
    }

    /**
     * Get the maximum number of calculations allowed per bulk request.
     * Based on Taapi Expert plan limits.
     *
     * @return int Maximum calculations per request
     */
    public function getMaxCalculationsPerRequest(): int
    {
        return 20;
    }

    /**
     * Override: conditionally ignore HTTP 400 based on error message content.
     *
     * Some 400s are ignorable (invalid symbol, malformed params) while others
     * indicate configuration issues that should cause the job to fail (plan limit exceeded).
     */
    public function ignoreException(Throwable $exception): bool
    {
        // First check if it would normally be ignored
        if (! $this->baseIgnoreException($exception)) {
            return false;
        }

        // If it's ignorable by HTTP code, check the response body for non-ignorable patterns
        // TAAPI returns {"errors": [...]} format, not {"code": ..., "msg": ...}
        if ($exception instanceof RequestException && $exception->hasResponse()) {
            $response = $exception->getResponse();
            if ($response === null) {
                return true;
            }

            $body = mb_strtolower((string) $response->getBody());

            // Check for patterns that should NOT be ignored
            foreach ($this->nonIgnorableErrorPatterns as $pattern) {
                if (! (str_contains(haystack: $body, needle: mb_strtolower($pattern)))) {
                    continue;
                }

                return false;
            }
        }

        return true;
    }

    /**
     * Override: compute retry time for Taapi's 15-second rate limit windows.
     *
     * Rules per Taapi.io:
     * • Rate limits are enforced in 15-second windows per plan
     * • HTTP 429 returned when limit exceeded with JSON error message
     * • No Retry-After or custom headers documented
     * • Window implementation details not specified (sliding vs. fixed)
     * • Conservative approach: wait full 3 seconds from rate limit
     */
    public function rateLimitUntil(RequestException $exception): Carbon
    {
        $now = Carbon::now();

        // 1) Use base logic first (honors Retry-After if present)
        $baseUntil = $this->baseRateLimitUntil($exception);

        // If parent already derived a future time (e.g., via Retry-After), keep it
        if ($baseUntil->greaterThan($now)) {
            return $baseUntil;
        }

        // 2) No Retry-After: wait a full 3 seconds from now
        // Since Taapi doesn't document window boundaries or reset timing,
        // conservatively wait 3 seconds
        $resetAt = $now->copy()->addSeconds(3);

        // Add small jitter to avoid stampede
        return $resetAt->copy()->addMilliseconds(random_int(100, 300));
    }

    /**
     * Override: calculate backoff for rate limits considering Taapi's windows.
     */
    public function backoffSeconds(Throwable $e): int
    {
        if ($e instanceof RequestException && $e->hasResponse()) {
            $response = $e->getResponse();
            if ($response !== null && ($this->isRateLimited($e) || $response->getStatusCode() === 429)) {
                $until = $this->rateLimitUntil($e);
                $delta = max(0, now()->diffInSeconds($until, false));

                return (int) max($delta, $this->backoffSeconds);
            }
        }

        return $this->backoffSeconds;
    }
}
