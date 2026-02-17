<?php

declare(strict_types=1);

namespace Kraite\Core\Models;

use Kraite\Core\Abstracts\BaseModel;

final class BinanceListenKey extends BaseModel
{
    public $timestamps = false;

    public static function forAccount(Account $account): ?self
    {
        return self::where('account_id', $account->id)->first();
    }
}
