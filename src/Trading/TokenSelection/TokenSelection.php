<?php

declare(strict_types=1);

namespace Kraite\Core\Trading\TokenSelection;

use Illuminate\Support\Facades\Facade;
use Kraite\Core\Models\Account;

/**
 * @method static AccountTokenSelection forAccount(Account $account)
 *
 * @see TokenSelectionManager
 */
final class TokenSelection extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return TokenSelectionManager::class;
    }
}
