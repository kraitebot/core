<?php

declare(strict_types=1);

namespace Kraite\Core\Observers;

use Kraite\Core\Models\Symbol;

final class SymbolObserver
{
    public function creating(Symbol $model): void {}

    public function created(Symbol $model): void {}

    public function updating(Symbol $model): void {}

    public function updated(Symbol $model): void {}

    public function deleted(Symbol $model): void {}

    public function forceDeleted(Symbol $model): void {}
}
