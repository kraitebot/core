<?php

declare(strict_types=1);

namespace Kraite\Core\Concerns\Account;

use Illuminate\Database\Eloquent\Builder;

trait HasScopes
{
    public function scopeActive(Builder $query)
    {
        return $query->where('accounts.is_active', true);
    }

    public function scopeTradeable(Builder $query)
    {
        return $query->where('accounts.can_trade', true);
    }
}
