<?php

declare(strict_types=1);

namespace Kraite\Core\Observers;

use Illuminate\Support\Str;
use Kraite\Core\Models\Account;

final class AccountObserver
{
    public function creating(Account $model): void
    {
        $model->uuid ??= Str::uuid()->toString();
    }
}
