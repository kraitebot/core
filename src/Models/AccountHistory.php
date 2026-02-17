<?php

declare(strict_types=1);

namespace Kraite\Core\Models;

use Kraite\Core\Abstracts\BaseModel;

final class AccountHistory extends BaseModel
{
    protected $table = 'account_history';

    protected $casts = [
        'balances' => 'array',
        'positions' => 'array',
        'raw' => 'array',
    ];

    public function account()
    {
        return $this->belongsTo(Account::class);
    }
}
