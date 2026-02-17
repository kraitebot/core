<?php

declare(strict_types=1);

namespace Kraite\Core\Observers;

use Illuminate\Support\Str;
use Kraite\Core\Models\Position;

final class PositionObserver
{
    public function creating(Position $model): void
    {
        $model->uuid ??= Str::uuid()->toString();
    }
}
