<?php

declare(strict_types=1);

namespace Kraite\Core\Concerns\Kraite;

use Kraite\Core\Models\User;

trait HasGetters
{
    /**
     * Get a virtual admin User for notifications.
     *
     * Returns a non-persisted User instance with admin notification credentials.
     * This virtual user can be used with Laravel's notification system while
     * preventing accidental persistence to the database.
     *
     * @return User Virtual user instance (exists = false, is_virtual = true)
     */
    public static function admin(): User
    {
        return once(static function () {
            $engine = self::findOrFail(1);

            return tap(new User, static function (User $user) use ($engine) {
                $user->exists = false;
                $user->is_virtual = true;
                $user->setAttribute('name', 'System Administrator');
                $user->setAttribute('email', $engine->email);
                $user->setAttribute('pushover_key', $engine->admin_pushover_user_key);
                $user->setAttribute('telegram_chat_id', $engine->admin_telegram_chat_id ?? null);
                $user->setAttribute('notification_channels', $engine->notification_channels ?? ['pushover']);
                $user->setAttribute('is_active', true);
            });
        });
    }

    /**
     * Global master trading switch. false halts ALL new position opens
     * (the first gate in HasTradingGuards::canOpenPositions()); existing
     * positions are never touched. Singleton column wins; NULL inherits
     * the config default. Read fresh (no memoisation) so a live flip on
     * the kraite row takes effect on the next cron tick without a deploy.
     */
    public static function canTrade(): bool
    {
        // Normally-open kill: NULL = trading allowed. Only an explicit
        // `false` on the singleton suspends. The legacy env CAN_TRADE
        // (default false) is deliberately not the fallback — it would
        // halt trading the moment this gate ships.
        return (self::first()?->can_trade ?? true) !== false;
    }

    /**
     * Global notifications master switch. false suppresses every
     * notification; true defers to the per-user toggle (except Critical).
     * Singleton column wins; NULL inherits the config default.
     */
    public static function notificationsEnabled(): bool
    {
        return self::first()?->notifications_enabled
            ?? (bool) config('kraite.notifications_enabled', true);
    }

    /**
     * Which BTC-correlation series token discovery reads
     * (rolling | pearson | spearman). Singleton column wins; NULL
     * inherits the config default.
     */
    public static function correlationType(): string
    {
        return self::first()?->td_correlation_type
            ?? (string) config('kraite.token_discovery.correlation_type', 'rolling');
    }

    /**
     * Whether the BTC-correlation computation pipeline runs. Singleton
     * column wins; NULL inherits the config default.
     */
    public static function correlationComputationEnabled(): bool
    {
        return self::first()?->corr_enabled
            ?? (bool) config('kraite.correlation.enabled', true);
    }

    /**
     * Whether the BTC-elasticity computation pipeline runs. Singleton
     * column wins; NULL inherits the config default.
     */
    public static function elasticityComputationEnabled(): bool
    {
        return self::first()?->elast_enabled
            ?? (bool) config('kraite.elasticity.enabled', true);
    }

    /**
     * Position-trail retention window in hours (0 = purge disabled).
     * Singleton column wins; NULL inherits the config default.
     */
    public static function trailRetentionHours(): int
    {
        return self::first()?->trail_retention_hours
            ?? (int) config('kraite.positions.trail_retention_hours', 0);
    }
}
