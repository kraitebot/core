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
     * Default profile — tuned via Plan A stress tests (2026-04-22):
     * - 75 requests per 15 seconds (matches TAAPI's window cap exactly)
     * - 50ms minimum delay between requests (just enough to avoid burst bunching)
     * - 1.0 safety threshold (no artificial buffer; throttler rides the cap)
     *
     * Under a 1000-step burst this profile drains at 92% of TAAPI's real cap
     * with ~20% of calls returning probe 429s — all caught by the is_throttled
     * reschedule path (zero retry budget burned, zero failures).
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
            'min_delay_between_requests_ms' => config('kraite.throttlers.taapi.min_delay_between_requests_ms', 50),
            'safety_threshold' => config('kraite.throttlers.taapi.safety_threshold', 1.0),
        ];
    }

    protected static function getCacheKeyPrefix(): string
    {
        return 'taapi_throttler';
    }
}
