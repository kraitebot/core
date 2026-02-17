<?php

declare(strict_types=1);

namespace Kraite\Core\Support\NotificationHandlers;

/**
 * BitgetNotificationHandler
 *
 * Maps Bitget API error codes to notification canonicals.
 *
 * HTTP Code Mappings:
 * - 400 with vendor codes: Various IP/auth errors → server_ip_forbidden
 * - 401: Authentication failed → server_ip_forbidden
 * - 403: IP banned/permission issues → server_ip_forbidden
 * - 429: Too many requests → server_rate_limit_exceeded
 *
 * Vendor Code Mappings (HTTP 400 responses):
 * - 40009: Sign signature error → server_ip_forbidden
 * - 40014: Invalid API key → server_ip_forbidden
 * - 40017: Parameter verification failed → server_ip_forbidden
 * - 40018: Invalid passphrase/IP → server_ip_forbidden
 * - 40037: API key does not exist → server_ip_forbidden
 */
final class BitgetNotificationHandler extends BaseNotificationHandler
{
    public array $serverForbiddenHttpCodes = [
        401,
        403,
        400 => [40018, 40009, 40014, 40017, 40037],
    ];

    public array $serverRateLimitedHttpCodes = [429];
}
