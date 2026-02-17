<?php

declare(strict_types=1);

namespace Kraite\Core\Support\NotificationHandlers;

/**
 * KucoinNotificationHandler
 *
 * Maps KuCoin API error codes to notification canonicals.
 *
 * HTTP Code Mappings:
 * - 401: Authentication failed → server_ip_forbidden
 * - 403: IP banned/permission issues → server_ip_forbidden
 * - 429: Too many requests → server_rate_limit_exceeded
 *
 * Vendor Code Mappings (HTTP 200 responses):
 * - 429000: Rate limit exceeded → server_rate_limit_exceeded
 * - 400100: Invalid API key → server_ip_forbidden
 * - 411100: User is frozen → server_ip_forbidden
 */
final class KucoinNotificationHandler extends BaseNotificationHandler
{
    public array $serverForbiddenHttpCodes = [
        401,
        403,
        200 => [400100, 411100],
    ];

    public array $serverRateLimitedHttpCodes = [
        429,
        200 => [429000],
    ];
}
