<?php

declare(strict_types=1);

namespace Kraite\Core\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\Notification;
use Kraite\Core\Models\NotificationLog;
use Kraite\Core\Models\User;
use Kraite\Core\Notifications\AlertNotification;
use Throwable;

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
     * In-process cache for `Notification` model lookups by canonical.
     *
     * `send()` is called from hot paths (every `ApiRequestLog::saved`
     * event, every position-lifecycle step) and each call previously
     * issued a `SELECT * FROM notifications WHERE canonical = ? LIMIT 1`
     * with no caching. Per-process cache eliminates the N+1 — the
     * `notifications` table is config-grade data that changes rarely.
     * Cleared between requests in worker processes via long-running PHP
     * boot, or `Once::flush()` in tests.
     *
     * @var array<string, ?Notification>
     */
    private static array $notificationCache = [];

    public static function flushNotificationCache(): void
    {
        self::$notificationCache = [];
    }

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

    private static function resolveNotification(string $canonical): ?Notification
    {
        if (! array_key_exists($canonical, self::$notificationCache)) {
            self::$notificationCache[$canonical] = Notification::where('canonical', $canonical)->first();
        }

        return self::$notificationCache[$canonical];
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

        // Load notification for throttle duration and cache key template.
        // Cached in-process to avoid one DB hit per send() call on hot paths.
        $notification = self::resolveNotification($canonical);

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

        // Build notification message from canonical template. Builder
        // throws InvalidArgumentException on unknown canonicals (typo at
        // call site) — we log & swallow so a coding mistake doesn't
        // crash the calling job/observer, but the failure is visible
        // in logs instead of producing a junk live notification.
        try {
            $messageData = NotificationMessageBuilder::build($canonical, $referenceData, $user);
        } catch (Throwable $e) {
            Log::error('[NotificationService] Failed to build message', [
                'canonical' => $canonical,
                'message' => $e->getMessage(),
            ]);

            return false;
        }

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

        // Send notification using Laravel's notification system.
        //
        // Failure-containment contract: a thrown channel exception
        // (Pushover 429, SMTP timeout, expired token, queue blip,
        // anything) MUST NOT propagate up to the caller. Notifications
        // are observability — the price daemon, the user-data daemon,
        // the health watchdog all call this synchronously, and a
        // bubbling exception cascades into "process dies → respawn →
        // mark prices stale → watchdog fires more notifications →
        // Pushover 429 again". 2026-05-03 incident.
        try {
            $user->notify(
                new AlertNotification(
                    message: $messageData['emailMessage'],
                    title: $messageData['title'],
                    canonical: $canonical,
                    severity: $messageData['severity'],
                    pushoverMessage: $messageData['pushoverMessage'],
                    additionalParameters: $additionalParameters,
                    telegramMessage: $messageData['telegramMessage'] ?? null,
                )
            );
        } catch (Throwable $e) {
            Log::warning('[NotificationService] notify() failed; swallowed to protect the caller', [
                'canonical' => $canonical,
                'recipient_id' => $user->id ?? null,
                'channel_error' => $e->getMessage(),
                'channel_exception' => $e::class,
            ]);

            return false;
        }

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
