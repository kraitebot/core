<?php

declare(strict_types=1);

namespace Kraite\Core\Observers;

use Kraite\Core\Models\ApiSnapshot;

final class ApiSnapshotObserver
{
    public function creating(ApiSnapshot $model): void {}

    public function updating(ApiSnapshot $model): void {}

    public function created(ApiSnapshot $model): void {}

    public function updated(ApiSnapshot $model): void {}

    public function deleted(ApiSnapshot $model): void {}

    public function forceDeleted(ApiSnapshot $model): void {}
}
