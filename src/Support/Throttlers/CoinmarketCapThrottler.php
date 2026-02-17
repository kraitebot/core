<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Throttlers;

use Kraite\Core\Abstracts\BaseApiThrottler;

/**
 * CoinmarketCapThrottler
 *
 * Rate limiter for CoinMarketCap API requests.
 * Configuration is loaded from config/kraite.php ('throttlers.coinmarketcap').
 *
 * Default: Free tier limits (30 requests per minute)
 * Adjust in config file based on your plan tier.
 *
 * CoinMarketCap Plan Tiers (as of 2025):
 * - Free/Hobbyist: 30 requests per minute
 * - Startup: 60 requests per minute
 * - Standard: 90 requests per minute
 * - Professional: 120 requests per minute
 * - Enterprise: 120+ requests per minute
 *
 * Usage:
 *   $secondsToWait = CoinmarketCapThrottler::canDispatch();
 *   if ($secondsToWait > 0) {
 *       // Need to wait, retry job with delay
 *       $this->retryJob(now()->addSeconds($secondsToWait));
 *       return;
 *   }
 *   CoinmarketCapThrottler::recordDispatch();
 *   // Make API request...
 */
final class CoinmarketCapThrottler extends BaseApiThrottler
{
    /**
     * CoinMarketCap Rate Limits (configurable via config/kraite.php)
     *
     * Default configuration: Free Tier
     * - 30 requests per 60 seconds (0.5 requests/second average)
     * - 2 seconds minimum delay between requests
     *
     * To adjust for higher tiers, update config/kraite.php:
     * 'throttlers.coinmarketcap.requests_per_window'
     * 'throttlers.coinmarketcap.window_seconds'
     * 'throttlers.coinmarketcap.min_delay_between_requests_ms'
     */
    protected static function getRateLimitConfig(): array
    {
        return [
            'requests_per_window' => config('kraite.throttlers.coinmarketcap.requests_per_window', 30),
            'window_seconds' => config('kraite.throttlers.coinmarketcap.window_seconds', 60),
            'min_delay_between_requests_ms' => config('kraite.throttlers.coinmarketcap.min_delay_between_requests_ms', 2000),
        ];
    }

    protected static function getCacheKeyPrefix(): string
    {
        return 'coinmarketcap_throttler';
    }
}
