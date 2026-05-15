<?php

declare(strict_types=1);

namespace Kraite\Core\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Kraite\Core\Models\User;

/**
 * Fired the moment a user's `email_verified_at` transitions from null
 * to a timestamp — i.e., the user has just clicked the verification
 * link on kraite.com (or any future flow that flips the column).
 *
 * Lives in `kraitebot/core` rather than in any single Laravel app
 * because both `kraite.com` (the marketing site that fires it) and
 * `ingestion.kraite.com` (the worker that listens for it via the
 * `AttachPrivateBetaCoupon` listener) need to reference the same class.
 * Carrying only the user id keeps the event serialization-safe across
 * the shared Redis queue.
 */
final class UserEmailConfirmed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly int $userId) {}

    /**
     * Convenience constructor — accept a User and store the id.
     */
    public static function for(User $user): self
    {
        return new self($user->id);
    }
}
