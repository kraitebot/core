<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Throttlers;

use Kraite\Core\Abstracts\BaseApiThrottler;

/**
 * TaapiThrottler
 *
 * Rate limiter for TAAPI.IO API requests.
 * Configuration is loaded from config/kraite.php ('throttlers.taapi').
 *
 * Default: Expert plan limits (75 requests per 15 seconds)
 * Adjust in config file based on your plan tier.
 *
 * Usage:
 *   $secondsToWait = TaapiThrottler::canDispatch();
 *   if ($secondsToWait > 0) {
 *       // Need to wait, retry job with delay
 *       $this->retryJob(now()->addSeconds($secondsToWait));
 *       return;
 *   }
 *   TaapiThrottler::recordDispatch();
 *   // Make API request...
 */
final class TaapiThrottler extends BaseApiThrottler
{
    /**
     * TAAPI Rate Limits (configurable via config/kraite.php)
     *
     * Default configuration: Expert Plan (Conservative profile)
     * - 75 requests per 15 seconds (300/min, 18,000/hour)
     * - 250ms minimum delay between requests
     * - 80% safety threshold (stops at 60/75 requests)
     *
     * To adjust, update config/kraite.php:
     * 'throttlers.taapi.requests_per_window'
     * 'throttlers.taapi.window_seconds'
     * 'throttlers.taapi.min_delay_between_requests_ms'
     * 'throttlers.taapi.safety_threshold'
     */
    protected static function getRateLimitConfig(): array
    {
        return [
            'requests_per_window' => config('kraite.throttlers.taapi.requests_per_window', 75),
            'window_seconds' => config('kraite.throttlers.taapi.window_seconds', 15),
            'min_delay_between_requests_ms' => config('kraite.throttlers.taapi.min_delay_between_requests_ms', 250),
            'safety_threshold' => config('kraite.throttlers.taapi.safety_threshold', 0.80),
        ];
    }

    protected static function getCacheKeyPrefix(): string
    {
        return 'taapi_throttler';
    }
}
