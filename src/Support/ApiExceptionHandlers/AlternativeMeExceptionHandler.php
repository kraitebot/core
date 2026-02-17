<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiExceptionHandlers;

use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Concerns\ApiExceptionHelpers;
use Throwable;

/**
 * AlternativeMeExceptionHandler
 *
 * Focus:
 * • Alternative.me is a simple public API
 * • Rate limit: 60 requests per minute (10-minute window)
 * • Returns 429 when rate limit exceeded
 * • No authentication required for public endpoints
 */
final class AlternativeMeExceptionHandler extends BaseExceptionHandler
{
    use ApiExceptionHelpers;

    /**
     * Ignorable — no-ops / idempotent.
     * 400: Bad request (malformed parameters)
     */
    public array $ignorableHttpCodes = [
        400,
    ];

    /**
     * Retryable — transient conditions.
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
     * Server forbidden — authentication/authorization failures.
     * 401: Unauthorized
     * 402: Payment required
     * 403: Forbidden
     *
     * NOTE: For AlternativeMe, all forbidden errors are account-specific.
     * Use isAccountBlocked() for specific classification.
     */
    public array $serverForbiddenHttpCodes = [
        401,
        402,
        403,
    ];

    /**
     * Account blocked — authentication/authorization failures.
     * These are all account-specific issues that require user action.
     *
     * @var array<int, array<int, int>|int>
     */
    public array $accountBlockedHttpCodes = [
        401,
        402,
        403,
    ];

    /**
     * Rate-limited — exceeded request quota.
     * 429: Too Many Requests (exceeded 60 requests per minute limit)
     */
    public array $serverRateLimitedHttpCodes = [
        429,
    ];

    /**
     * recvWindow mismatches: not applicable for Alternative.me.
     */
    public array $recvWindowMismatchedHttpCodes = [];

    public function __construct()
    {
        // Conservative backoff for simple API
        $this->backoffSeconds = 5;
    }

    /**
     * Ping the Alternative.me API to check connectivity.
     */
    public function ping(): bool
    {
        // Alternative.me doesn't have a dedicated ping endpoint
        // Return true as we'll discover issues through actual API calls
        return true;
    }

    public function getApiSystem(): string
    {
        return 'alternativeme';
    }

    /**
     * Case 4: Account blocked.
     * For AlternativeMe, this includes all authentication/authorization failures.
     * All require user action to resolve.
     */
    public function isAccountBlocked(Throwable $exception): bool
    {
        return $this->containsHttpExceptionIn($exception, $this->accountBlockedHttpCodes);
    }
}
