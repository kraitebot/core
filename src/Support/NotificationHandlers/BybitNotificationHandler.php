<?php

declare(strict_types=1);

namespace Kraite\Core\Support\NotificationHandlers;

/**
 * BybitNotificationHandler
 *
 * Maps Bybit API error codes to notification canonicals.
 *
 * HTTP Code Mappings:
 * - 403: IP rate limit breached → server_rate_limit_exceeded
 * - 429: IP auto-banned → server_rate_limit_exceeded
 * - 200 with 10006/10018/170005/170222: Rate limit errors → server_rate_limit_exceeded
 *
 * NOTE: The previous "forbidden" set (401 + 200/10003-10010) is intentionally
 * removed. Every code in that set is also classified by the exception path
 * (`isAccountBlocked` covers 401 + 10003/10004/10005/10007;
 * `ipNotWhitelistedHttpCodes` covers 10010; `ipBannedHttpCodes` covers
 * 10009), which writes a `ForbiddenHostname` row and triggers the specific
 * canonical via `ForbiddenHostnameObserver`. Emitting a broad
 * `server_ip_forbidden` here as well produced double notifications for the
 * same incident.
 */
final class BybitNotificationHandler extends BaseNotificationHandler
{
    public array $serverForbiddenHttpCodes = [];

    public array $serverRateLimitedHttpCodes = [
        403,
        429,
        200 => [
            10006,   // Too many visits (per-UID)
            10018,   // Exceeded IP rate limit
            170005,  // Exceeded max orders per time period
            170222,  // Too many requests
        ],
    ];

    /**
     * Extract vendor error code from Bybit response.
     * Bybit uses 'retCode' field. A retCode of 0 means success.
     */
    public function extractVendorCode(?array $response): ?int
    {
        $retCode = $response['retCode'] ?? null;

        if ($retCode === null || (int) $retCode === 0) {
            return null;
        }

        return (int) $retCode;
    }
}
