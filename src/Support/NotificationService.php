<?php

declare(strict_types=1);

namespace Kraite\Core\Support;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Auth\User as AuthUser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Kraite\Core\Enums\NotificationLogStatus;
use Kraite\Core\Enums\NotificationSeverity;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\Notification;
use Kraite\Core\Models\NotificationLog;
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
        AuthUser $user,
        string $canonical,
        array $referenceData = [],
        ?object $relatable = null,
        ?int $duration = null,
        ?array $cacheKeys = null,
        ?array $channels = null,
    ): bool {
        return self::sendToSpecificUser(
            user: $user,
            canonical: $canonical,
            referenceData: $referenceData,
            relatable: $relatable,
            duration: $duration,
            cacheKeys: $cacheKeys,
            channels: $channels,
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
        AuthUser $user,
        string $canonical,
        array $referenceData = [],
        ?object $relatable = null,
        ?int $duration = null,
        ?array $cacheKeys = null,
        ?array $channels = null,
    ): bool {
        if (FreezeMode::isActive()) {
            return false;
        }

        // Tier 1 — global master switch. Singleton column wins, else
        // config. false here suppresses every notification, no exceptions.
        if (! Kraite::notificationsEnabled()) {
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

        // Build cache key string if cacheKeys data is provided.
        //
        // Failure-containment contract: a caller-supplied $cacheKeys missing
        // a required template key (seed/caller drift) MUST NOT propagate out
        // of NotificationService — the call site is typically a hot-path
        // observer or watchdog. Mirror the same try/catch shape used for
        // NotificationMessageBuilder::build() and $user->notify() below:
        // log a precise error with canonical / supplied keys / template,
        // then return false so the originating job continues.
        $builtCacheKey = null;
        if ($cacheKeys && $notification && $notification->cache_key) {
            try {
                $builtCacheKey = self::buildCacheKey($canonical, $cacheKeys, $notification->cache_key);
            } catch (Throwable $e) {
                Log::error('[NotificationService] Failed to build throttle key', [
                    'canonical' => $canonical,
                    'message' => $e->getMessage(),
                    'cache_keys' => array_keys($cacheKeys),
                    'template' => $notification->cache_key,
                ]);

                return false;
            }
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

                // Only actually-delivered rows count toward the throttle window.
                // This is a no-op for non-threshold notifications (every row they
                // write is passed_threshold=true), but it stops a threshold's
                // recorded-only "held" rows from being mistaken for a recent
                // delivery — otherwise a notification with both a throttle window
                // and a threshold could never accumulate enough to breach.
                $isThrottled = NotificationLog::query()
                    ->where('canonical', $canonical)
                    ->where('relatable_type', get_class($throttleRelatable))
                    ->where('relatable_id', $throttleRelatable->id)
                    ->where('passed_threshold', true)
                    ->where('created_at', '>', now()->subSeconds($throttleDuration))
                    ->exists();

                if ($isThrottled) {
                    // Still within throttle window - skip sending
                    return false;
                }
            }
        }

        // Notification Threshold — opt-in escalation gate layered on top of the
        // throttler. For a notification flagged has_threshold, an occurrence is
        // only physically delivered once it has recurred
        // threshold_max_notifications times within a rolling
        // threshold_max_duration_minutes window. Sub-threshold occurrences are
        // still recorded in notification_logs (passed_threshold=false) but not
        // sent. A misconfigured threshold (missing/zero count or window) is
        // treated as "no threshold" and sends normally — fail-open, consistent
        // with the rest of this service. Anything without a threshold falls
        // straight through to the unchanged send path below.
        if ($notification
            && $notification->has_threshold
            && (int) $notification->threshold_max_notifications >= 1
            && (int) $notification->threshold_max_duration_minutes >= 1
            && ! self::breachesThreshold($notification, $relatable, $user)
        ) {
            return false;
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

        // Tier 2 — per-user toggle. With the global switch on, a user can
        // opt out of their own notifications, EXCEPT Critical-severity ones
        // which are always delivered to the account user. A NULL/absent
        // flag counts as enabled, so existing users keep receiving until
        // they explicitly opt out. The virtual admin (Kraite::admin()) has
        // no column set → null → always receives.
        $isCritical = ($messageData['severity'] ?? null) === NotificationSeverity::Critical;

        if (! $isCritical && ($user->notifications_enabled ?? true) === false) {
            return false;
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
        // or a routine WAP event at normal priority 0 even though its
        // severity remains High for email/dashboard presentation.
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
                    relatable: $relatable,
                    emailBlocks: $messageData['emailBlocks'] ?? null,
                    forceChannels: $channels,
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

    /**
     * Evaluate the notification threshold for the current occurrence.
     *
     * Returns true when the occurrence BREACHES the threshold (the caller should
     * proceed to send for real) and false when it is sub-threshold (this method
     * has already recorded the held occurrence in notification_logs and the
     * caller must NOT send).
     *
     * Counting model: only "held" rows (passed_threshold=false) accumulate
     * toward the next breach — they are written exactly one-per-occurrence by
     * this service, so the count is occurrence-accurate even when a delivered
     * occurrence fans out to several per-channel rows. Re-earn resets via a
     * cache anchor holding the id high-water mark at each breach: only held rows
     * with a higher id (and still inside the rolling window) count toward the
     * next breach, so after a breach the counter starts fresh and the admin is
     * alerted on every Nth occurrence, never the lone ones. The anchor is a row
     * id rather than a timestamp so sub-second bursts can't collide, and it
     * lives in the same cache store the throttler uses, so the reset is
     * consistent across worker servers.
     */
    private static function breachesThreshold(Notification $notification, ?object $relatable, AuthUser $user): bool
    {
        $windowMinutes = (int) $notification->threshold_max_duration_minutes;

        [$relatableType, $relatableId] = self::resolveThresholdRelatable($relatable);

        $breachCacheKey = sprintf(
            'notification_threshold_breach:%d:%s:%s',
            $notification->id,
            $relatableType ?? '',
            $relatableId ?? ''
        );

        // Serialize the read-count → decide → advance-anchor sequence across
        // worker servers. Without it two workers can both read pending = N-1,
        // both breach, and the admin gets a duplicate alert at the very moment
        // this feature exists to suppress one. The lock is scoped per
        // (notification, relatable). If it can't be acquired in time we evaluate
        // anyway — a rare double alert beats blocking the notification path
        // (fail-open, consistent with the rest of this service).
        $lock = Cache::lock($breachCacheKey.':lock', 10);

        try {
            return $lock->block(5, static function () use ($notification, $user, $breachCacheKey, $windowMinutes, $relatableType, $relatableId): bool {
                return self::evaluateThresholdBreach($notification, $user, $breachCacheKey, $windowMinutes, $relatableType, $relatableId);
            });
        } catch (LockTimeoutException) {
            return self::evaluateThresholdBreach($notification, $user, $breachCacheKey, $windowMinutes, $relatableType, $relatableId);
        }
    }

    /**
     * Count → decide → record step of the threshold, run inside the breach lock
     * (see breachesThreshold). Returns true on breach (the caller delivers) and
     * false on a held occurrence (recorded here, not delivered).
     *
     * Re-earn anchor: the id high-water mark of held rows at the last breach.
     * Using the monotonic row id (not a timestamp) keeps the reset immune to
     * sub-second bursts where several occurrences share the same created_at
     * second. Held in the same cache store the throttler uses, so the reset is
     * consistent across worker servers.
     */
    private static function evaluateThresholdBreach(
        Notification $notification,
        AuthUser $user,
        string $breachCacheKey,
        int $windowMinutes,
        ?string $relatableType,
        int|string|null $relatableId
    ): bool {
        $maxNotifications = (int) $notification->threshold_max_notifications;

        $anchorId = (int) (Cache::get($breachCacheKey) ?? 0);
        $windowStart = now()->subMinutes($windowMinutes);

        $heldQuery = NotificationLog::query()
            ->where('notification_id', $notification->id)
            ->where('passed_threshold', false)
            ->when(
                $relatableType === null,
                static function ($query) {
                    $query->whereNull('relatable_type')->whereNull('relatable_id');
                },
                static function ($query) use ($relatableType, $relatableId) {
                    $query->where('relatable_type', $relatableType)->where('relatable_id', $relatableId);
                }
            );

        // Occurrences held since the last breach, still inside the rolling window.
        $pendingCount = (clone $heldQuery)
            ->where('id', '>', $anchorId)
            ->where('created_at', '>', $windowStart)
            ->count();

        // The current occurrence is the (pending + 1)th since the last reset.
        if ($pendingCount + 1 >= $maxNotifications) {
            // Breach — consume the cycle's held rows by advancing the anchor to
            // the current high-water mark, then let the caller deliver for real.
            // TTL kept just over the window so a quiet stretch naturally re-arms.
            $highWaterMark = (int) ((clone $heldQuery)->max('id') ?? $anchorId);
            Cache::put($breachCacheKey, $highWaterMark, now()->addMinutes($windowMinutes + 1));

            return true;
        }

        self::recordThresholdHeld($notification, $user, $relatableType, $relatableId);

        return false;
    }

    /**
     * Record a sub-threshold occurrence: logged for audit, deliberately not
     * sent. Mirrors the relatable resolution used by NotificationLogListener so
     * held rows sit in the same scope as the eventual delivered rows.
     *
     * Failure-containment: a write failure here must never break the caller —
     * the worst case is a slightly under-counted threshold, not a crashed job.
     */
    private static function recordThresholdHeld(
        Notification $notification,
        AuthUser $user,
        ?string $relatableType,
        int|string|null $relatableId
    ): void {
        try {
            NotificationLog::create([
                'notification_id' => $notification->id,
                'canonical' => $notification->canonical,
                'user_id' => $user->id ?? null,
                'relatable_type' => $relatableType,
                'relatable_id' => $relatableId,
                'channel' => 'none',
                'recipient' => 'none',
                'sent_at' => now(),
                'status' => NotificationLogStatus::ThresholdHeld->value,
                'passed_threshold' => false,
            ]);
        } catch (Throwable $e) {
            Log::warning('[NotificationService] Failed to record threshold-held occurrence', [
                'canonical' => $notification->canonical,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Resolve a relatable into the (type, id) tuple used to scope the threshold
     * count. Matches NotificationLogListener::resolveRelatableTuple so the held
     * rows written here and the delivered rows written by the listener share
     * one scope. A null relatable scopes to the system-level bucket.
     *
     * @return array{string|null, int|string|null}
     */
    private static function resolveThresholdRelatable(?object $relatable): array
    {
        if ($relatable === null) {
            return [null, null];
        }

        if (method_exists($relatable, 'getMorphClass')) {
            return [$relatable->getMorphClass(), $relatable->getKey()];
        }

        if (property_exists($relatable, 'id')) {
            return [$relatable::class, $relatable->id];
        }

        // A relatable we can't key (no morph class, no id) collapses to the
        // system-level bucket, where it would share a threshold counter with
        // every other null-scoped notification. Surface it so a caller passing
        // an unkeyable object is caught rather than silently mis-counted.
        Log::warning('[NotificationService] Threshold relatable could not be keyed; scoping to system-level bucket', [
            'relatable_class' => $relatable::class,
        ]);

        return [null, null];
    }
}
