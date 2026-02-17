<?php

declare(strict_types=1);

namespace Kraite\Core\Observers;

use Kraite\Core\Models\User;

final class UserObserver
{
    public function creating(User $model): void
    {
        // $model->cacheChangesForCreate();
    }

    public function created(User $model): void {}

    public function updating(User $model): void
    {
        // $model->cacheChangesForUpdate();

        // Clear bounce behaviour flag when email changes
        if ($model->isDirty('email')) {
            $behaviours = $model->behaviours ?? [];
            unset($behaviours['should_announce_bounced_email']);
            $model->behaviours = $behaviours;
        }
    }

    public function updated(User $model): void {}

    public function deleted(User $model): void {}

    public function forceDeleted(User $model): void {}
}
