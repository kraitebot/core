<?php

declare(strict_types=1);

namespace Kraite\Core\Trading\TokenSelection;

use Kraite\Core\Models\Account;

final class TokenSelectionManager
{
    public function forAccount(Account $account): AccountTokenSelection
    {
        return new AccountTokenSelection($account);
    }
}
