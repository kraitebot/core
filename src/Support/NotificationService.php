<?php

declare(strict_types=1);

namespace Kraite\Core\Support;

use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\Notification;
use Kraite\Core\Models\NotificationLog;
use Kraite\Core\Models\User;
use Kraite\Core\Notifications\AlertNotification;

/**
 * NotificationService
 *
 * Unified notification service that leverages Laravel's notification system.
 *
 * Usage:
 *   // Admin notification
 *   NotificationService::send(
 *       user: Kraite::admin(),
 *       canonical: 'server_rate_limit_exceeded',
 *       referenceData: ['exchange' => 'binance']
 *   );
 *
 *   // User notification
 *   // Removed NotificationService::send with invalid canonical: price_alert
 */
final class NotificationService
{
    /**
     * Send a notification to a specific user.
     *
     * @param  User  $user  The user to send to (use Kraite::admin() for admin notifications)
     * @param  string  $canonical  Notification canonical identifier (e.g., 'server_rate_limit_exceeded')
     * @param  array<string, mixed>  $referenceData  Reference data for template interpolation (e.g., ['exchange' => 'binance'])
     * @param  object|null  $relatable  Optional relatable model for audit trail
     * @param  int|null  $duration  Throttle duration in seconds (null = use default from notifications table, 0 = no throttle, >0 = custom throttle window)
     * @param  array<string, mixed>|null  $cacheKeys  Optional cache key data for cache-based throttling (e.g., ['api_system' => 'binance', 'account' => 1]). If null, uses database-based throttling via notification_logs.
     * @return bool True if notification was sent, false otherwise
     */
    public static function send(
        User $user,
        string $canonical,
        array $referenceData = [],
        ?object $relatable = null,
        ?int $duration = null,
        ?array $cacheKeys = null
    ): bool {
        return self::sendToSpecificUser(
            user: $user,
            canonical: $canonical,
            referenceData: $referenceData,
            relatable: $relatable,
            duration: $duration,
            cacheKeys: $cacheKeys
        );
    }

    /**
     * Send notification to a specific user (internal use only).
     * Handles throttling, message building, and actual notification dispatch.
     *
     * @param  User  $user  The user to notify (real User or virtual admin via Kraite::admin())
     * @param  string  $canonical  Notification canonical identifier
     * @param  array<string, mixed>  $referenceData  Reference data for template interpolation
     * @param  object|null  $relatable  Optional relatable model for audit trail
     * @param  int|null  $duration  Throttle duration in seconds
     * @param  array<string, mixed>|null  $cacheKeys  Optional cache key data for throttling
     * @return bool True if notification was sent, false otherwise
     */
    private static function sendToSpecificUser(
        User $user,
        string $canonical,
        array $referenceData = [],
        ?object $relatable = null,
        ?int $duration = null,
        ?array $cacheKeys = null
    ): bool {
        // Check if notifications are globally enabled
        if (! config('kraite.notifications_enabled', true)) {
            return false;
        }

        // Load notification for throttle duration and cache key template
        $notification = Notification::where('canonical', $canonical)->first();

        // Check if this specific notification is active
        if ($notification && ! $notification->is_active) {
            return false;
        }

        // Determine throttle duration:
        // - null: use default from notifications table
        // - 0: no throttling (send immediately)
        // - >0: use custom duration
        $throttleDuration = $duration ?? $notification?->cache_duration;

        // Build cache key string if cacheKeys data is provided
        $builtCacheKey = null;
        if ($cacheKeys && $notification && $notification->cache_key) {
            $builtCacheKey = self::buildCacheKey($canonical, $cacheKeys, $notification->cache_key);
        }

        // Throttle check: only if throttleDuration is set and > 0
        if ($throttleDuration !== null && $throttleDuration > 0) {
            if ($builtCacheKey) {
                // Cache-based throttling with atomic operation
                // Cache::add() only sets the key if it doesn't exist (atomic SETNX operation in Redis)
                // Returns true if key was successfully set (we won the race), false if key already exists
                if (! Cache::add($builtCacheKey, true, $throttleDuration)) {
                    // Key already existed - another worker got here first
                    // This prevents race conditions across multiple worker servers
                    return false;
                }
                // Key was set successfully - we won the race, continue to send notification
            } else {
                // Database-based throttling (default fallback)
                // Use $relatable if provided, otherwise use $user as the throttle relatable
                $throttleRelatable = $relatable ?? $user;

                $isThrottled = NotificationLog::query()
                    ->where('canonical', $canonical)
                    ->where('relatable_type', get_class($throttleRelatable))
                    ->where('relatable_id', $throttleRelatable->id)
                    ->where('created_at', '>', now()->subSeconds($throttleDuration))
                    ->exists();

                if ($isThrottled) {
                    // Still within throttle window - skip sending
                    return false;
                }
            }
        }

        // Build notification message from canonical template
        $messageData = NotificationMessageBuilder::build($canonical, $referenceData, $user);

        if (! $messageData) {
            return false;
        }

        // Add relatable model to user for NotificationLogListener to extract
        if ($relatable) {
            $user->relatable = $relatable;
        }

        // Build additional parameters with action URL if provided
        $additionalParameters = [];
        if ($messageData['actionUrl']) {
            $additionalParameters['url'] = $messageData['actionUrl'];
            $additionalParameters['url_title'] = $messageData['actionLabel'] ?? 'View Details';
        }

        // Optional Pushover priority override: the canonical's template may
        // specify a priority (int in -2..2) that doesn't match the default
        // severity-derived mapping in AlertNotification (Critical → 2, else
        // 0). Trading-event canonicals use this to pick the right device
        // behaviour — e.g. Info severity delivered at priority -1 (silent),
        // or a WAP event at priority 1 (bypass quiet hours) even though
        // its severity is High.
        if (isset($messageData['priority']) && is_int($messageData['priority'])) {
            $additionalParameters['priority'] = $messageData['priority'];
        }

        // Send notification using Laravel's notification system
        $user->notify(
            new AlertNotification(
                message: $messageData['emailMessage'],
                title: $messageData['title'],
                canonical: $canonical,
                severity: $messageData['severity'],
                pushoverMessage: $messageData['pushoverMessage'],
                additionalParameters: $additionalParameters
            )
        );

        // Cache key already set atomically before sending (for cache-based throttling)
        // No need to set it again here

        return true;
    }

    /**
     * Build cache key string from canonical, data array, and template array.
     *
     * Format: {canonical}-{key1}:{value1},{key2}:{value2}
     * Example: server_rate_limit_exceeded-api_system:binance,account:1
     *
     * @param  string  $canonical  The notification canonical
     * @param  array<string, mixed>  $data  The cache key data provided by caller (e.g., ['api_system' => 'binance', 'account' => 1])
     * @param  array<int, string>  $template  The required keys from notifications table (e.g., ['api_system', 'account'])
     * @return string The built cache key
     *
     * @throws InvalidArgumentException If required keys are missing
     */
    private static function buildCacheKey(string $canonical, array $data, array $template): string
    {
        // Validate all required keys are present
        $missingKeys = [];
        foreach ($template as $requiredKey) {
            if (array_key_exists(key: $requiredKey, array: $data)) {
                continue;
            }

            $missingKeys[] = $requiredKey;
        }

        if (! empty($missingKeys)) {
            throw new InvalidArgumentException(
                "Missing required cache keys for canonical '{$canonical}': ".implode(separator: ', ', array: $missingKeys)
            );
        }

        // Build key construction: key1:value1,key2:value2
        $parts = [];
        foreach ($template as $key) {
            $value = $data[$key];
            $parts[] = "{$key}:{$value}";
        }

        $construction = implode(separator: ',', array: $parts);

        // Final format: {canonical}-{construction}
        return "{$canonical}-{$construction}";
    }
}
