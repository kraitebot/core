<?php

declare(strict_types=1);

namespace Kraite\Core\Support\NotificationHandlers;

/**
 * BinanceNotificationHandler
 *
 * Maps Binance API error codes to notification canonicals.
 *
 * HTTP Code Mappings:
 * - 429: Too many requests → server_rate_limit_exceeded
 * - 400 with -1003: WAF limit exceeded → server_rate_limit_exceeded
 *
 * NOTE: 418 is intentionally NOT mapped to a broad `server_ip_forbidden`
 * notification here. The exception path classifies every 418 as either
 * `isIpRateLimited` or `isIpBanned`, which writes a `ForbiddenHostname` row
 * and triggers the more specific `server_ip_rate_limited` /
 * `server_ip_banned` notification via `ForbiddenHostnameObserver`. Emitting
 * the broad notification too produced minute-cadence operator spam during
 * sustained IP bans (the specific cadence is hourly).
 */
final class BinanceNotificationHandler extends BaseNotificationHandler
{
    public array $serverForbiddenHttpCodes = [];

    public array $serverRateLimitedHttpCodes = [
        429,
        400 => [-1003],
    ];
}
