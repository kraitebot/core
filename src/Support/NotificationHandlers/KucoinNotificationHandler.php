<?php

declare(strict_types=1);

namespace Kraite\Core\Support\NotificationHandlers;

/**
 * KucoinNotificationHandler
 *
 * Maps KuCoin API error codes to notification canonicals.
 *
 * HTTP Code Mappings:
 * - 403: IP banned/permission issues → server_ip_forbidden (broad — kept,
 *   not covered by any exception-path is* method)
 * - 429: Too many requests → server_rate_limit_exceeded
 *
 * Vendor Code Mappings (HTTP 200 responses):
 * - 429000: Rate limit exceeded → server_rate_limit_exceeded
 *
 * NOTE: 401 and 200/(400100|411100) are intentionally NOT mapped here.
 * `isAccountBlocked` in the exception path covers 401 directly and the
 * KuCoin vendor codes via response-body inspection, writing a
 * `ForbiddenHostname` row and triggering `server_account_blocked` via
 * `ForbiddenHostnameObserver`. The broad `server_ip_forbidden` was
 * duplicating those.
 *
 * 403 stays here because the KuCoin exception handler does NOT classify it
 * as account/IP-specific — without this entry, an unclassified 403 would
 * produce no operator notification at all.
 */
final class KucoinNotificationHandler extends BaseNotificationHandler
{
    public array $serverForbiddenHttpCodes = [403];

    public array $serverRateLimitedHttpCodes = [
        429,
        200 => [429000],
    ];
}
