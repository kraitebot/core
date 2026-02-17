<?php

declare(strict_types=1);

namespace Kraite\Core\Concerns\Engine;

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
                $user->setAttribute('notification_channels', $engine->notification_channels ?? ['pushover']);
                $user->setAttribute('is_active', true);
            });
        });
    }
}
