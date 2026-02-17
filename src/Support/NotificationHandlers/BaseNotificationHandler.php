<?php

declare(strict_types=1);

namespace Kraite\Core\Support\NotificationHandlers;

use Exception;

/**
 * BaseNotificationHandler
 *
 * Abstract base for mapping HTTP error codes to notification canonicals.
 * Each API system (Binance, Bybit) has its own handler with specific mappings.
 *
 * This is separate from ExceptionHandler which handles job retry/backoff logic.
 * NotificationHandler only decides WHICH notification to send based on HTTP errors.
 */
abstract class BaseNotificationHandler
{
    /**
     * HTTP codes that indicate server IP is forbidden/banned.
     *
     * @var array<int, array<int, int>|int>
     */
    public array $serverForbiddenHttpCodes = [];

    /**
     * HTTP codes that indicate server rate limiting.
     *
     * @var array<int, array<int, int>|int>
     */
    public array $serverRateLimitedHttpCodes = [];

    /**
     * Get the notification canonical for a given HTTP code and vendor code.
     *
     * @param  int  $httpCode  The HTTP response code (e.g., 418, 429, 200)
     * @param  int|null  $vendorCode  The vendor-specific error code from response body (e.g., -1003 for Binance, 10018 for Bybit)
     * @return string|null The notification canonical (e.g., 'server_ip_forbidden') or null if no notification should be sent
     */
    public function getCanonical(int $httpCode, ?int $vendorCode): ?string
    {
        if ($this->matchesMapping($httpCode, $vendorCode, $this->serverRateLimitedHttpCodes)) {
            return 'server_rate_limit_exceeded';
        }

        if ($this->matchesMapping($httpCode, $vendorCode, $this->serverForbiddenHttpCodes)) {
            return 'server_ip_forbidden';
        }

        return null;
    }

    /**
     * Factory method to create the appropriate handler for an API system.
     *
     * @param  string  $apiCanonical  The API system canonical (e.g., 'binance', 'bybit')
     * @return static The appropriate handler instance
     *
     * @throws Exception If no handler exists for the API system
     */
    public static function make(string $apiCanonical): self
    {
        return match ($apiCanonical) {
            'binance' => new BinanceNotificationHandler,
            'bybit' => new BybitNotificationHandler,
            'kucoin' => new KucoinNotificationHandler,
            'bitget' => new BitgetNotificationHandler,
            default => throw new Exception("No NotificationHandler for API system: {$apiCanonical}")
        };
    }

    /**
     * Extract vendor-specific error code from response body.
     *
     * Default implementation checks common field names ('code').
     * Override in exchange-specific handlers for different response formats.
     *
     * @param  array<string, mixed>|null  $response  The decoded response body
     * @return int|null The vendor error code, or null if not present/not an error
     */
    public function extractVendorCode(?array $response): ?int
    {
        $code = $response['code'] ?? null;

        return $code !== null ? (int) $code : null;
    }

    /**
     * Check if an HTTP code matches a mapping array.
     *
     * Supports both flat arrays [418, 429] and nested arrays [200 => [10003, 10004]].
     *
     * @param  int  $httpCode  The HTTP response code
     * @param  int|null  $vendorCode  The vendor-specific error code
     * @param  array<int, array<int, int>|int>  $mappings  The mappings array to check against
     */
    protected function matchesMapping(int $httpCode, ?int $vendorCode, array $mappings): bool
    {
        // Check if httpCode exists as flat element (e.g., [418, 429])
        if (in_array($httpCode, $mappings, strict: true)) {
            return true;
        }

        // If no vendor code, we can't check nested structure
        if ($vendorCode === null) {
            return false;
        }

        // Check nested array structure (e.g., [200 => [10003, 10004]])
        foreach ($mappings as $code => $subCodes) {
            if ($code === $httpCode && is_array($subCodes) && in_array($vendorCode, $subCodes, strict: true)) {
                return true;
            }
        }

        return false;
    }
}
