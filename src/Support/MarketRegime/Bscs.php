<?php

declare(strict_types=1);

namespace Kraite\Core\Support\MarketRegime;

use Illuminate\Support\Facades\Facade;
use Kraite\Core\Models\Account;

/**
 * Public entry point for every Black Swan Composite Score decision.
 *
 * @method static BscsState current()
 * @method static BscsAccountContext forAccount(Account $account, ?BscsState $state = null)
 *
 * @see BscsManager
 */
final class Bscs extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return BscsManager::class;
    }
}
