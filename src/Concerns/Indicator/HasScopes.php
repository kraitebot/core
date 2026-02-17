<?php

declare(strict_types=1);

namespace Kraite\Core\Concerns\Indicator;

use Illuminate\Database\Eloquent\Builder;

trait HasScopes
{
    public function scopeActive(Builder $query)
    {
        return $query->where('indicators.is_active', true);
    }

    public function scopeFromApi(Builder $query)
    {
        return $query->where('indicators.is_computed', false);
    }

    public function scopeComputed(Builder $query)
    {
        return $query->where('indicators.is_computed', true);
    }
}
