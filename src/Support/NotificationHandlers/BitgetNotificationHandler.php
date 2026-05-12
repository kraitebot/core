<?php

declare(strict_types=1);

namespace Kraite\Core\Support\NotificationHandlers;

/**
 * BitgetNotificationHandler
 *
 * Maps Bitget API error codes to notification canonicals.
 *
 * HTTP Code Mappings:
 * - 403: IP banned/permission issues → server_ip_forbidden (broad — kept,
 *   not covered by any exception-path is* method)
 * - 429: Too many requests → server_rate_limit_exceeded
 *
 * NOTE: 401 and the 400/vendor-code set (40009/40014/40017/40018/40037) are
 * intentionally NOT mapped here. Both are classified by `isAccountBlocked`
 * in the exception path (HTTP 401 directly, vendor codes via response-body
 * inspection), which writes a `ForbiddenHostname` row and triggers
 * `server_account_blocked` via `ForbiddenHostnameObserver`. The broad
 * `server_ip_forbidden` was duplicating those.
 *
 * 403 stays here because the Bitget exception handler does NOT classify it
 * as account/IP-specific — without this entry, an unclassified 403 would
 * produce no operator notification at all.
 */
final class BitgetNotificationHandler extends BaseNotificationHandler
{
    public array $serverForbiddenHttpCodes = [403];

    public array $serverRateLimitedHttpCodes = [429];
}
