<?php

declare(strict_types=1);

namespace Kraite\Core\Models;

use Kraite\Core\Abstracts\BaseModel;

final class AccountBalanceHistory extends BaseModel
{
    protected $table = 'account_balance_history';

    public function account()
    {
        return $this->belongsTo(Account::class);
    }
}
