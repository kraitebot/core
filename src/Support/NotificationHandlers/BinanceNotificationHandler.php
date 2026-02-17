<?php

declare(strict_types=1);

namespace Kraite\Core\Support\NotificationHandlers;

/**
 * BinanceNotificationHandler
 *
 * Maps Binance API error codes to notification canonicals.
 *
 * HTTP Code Mappings:
 * - 418: IP auto-banned (teapot error) → server_ip_forbidden
 * - 429: Too many requests → server_rate_limit_exceeded
 * - 400 with -1003: WAF limit exceeded → server_rate_limit_exceeded
 */
final class BinanceNotificationHandler extends BaseNotificationHandler
{
    public array $serverForbiddenHttpCodes = [418];

    public array $serverRateLimitedHttpCodes = [
        429,
        400 => [-1003],
    ];
}
