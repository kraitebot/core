<?php

declare(strict_types=1);

namespace Kraite\Core\Observers;

use Kraite\Core\Models\AccountBalanceHistory;

final class AccountBalanceHistoryObserver
{
    public function creating(AccountBalanceHistory $model): void {}

    public function updating(AccountBalanceHistory $model): void {}

    public function created(AccountBalanceHistory $model): void {}

    public function updated(AccountBalanceHistory $model): void {}

    public function deleted(AccountBalanceHistory $model): void {}

    public function forceDeleted(AccountBalanceHistory $model): void {}
}
