<?php

declare(strict_types=1);

namespace Kraite\Core\Concerns\TradeConfiguration;

use Illuminate\Database\Eloquent\Builder;

trait HasScopes
{
    public function scopeDefault(Builder $query)
    {
        return $query->where('trade_configuration.is_default', true);
    }
}
