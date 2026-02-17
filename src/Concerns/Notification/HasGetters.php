<?php

declare(strict_types=1);

namespace Kraite\Core\Concerns\Notification;

trait HasGetters
{
    /**
     * Find a notification by canonical identifier.
     */
    public static function findByCanonical(string $canonical): ?self
    {
        return self::where('canonical', $canonical)->first();
    }

    /**
     * Check if a notification canonical exists.
     */
    public static function exists(string $canonical): bool
    {
        return self::where('canonical', $canonical)->exists();
    }

    /**
     * Get all notification canonicals as an array.
     *
     * @return array<int, string>
     */
    public static function allCanonicals(): array
    {
        /** @var array<int, string> */
        return self::pluck('canonical')->toArray();
    }
}
