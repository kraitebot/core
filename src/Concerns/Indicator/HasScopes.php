<?php

declare(strict_types=1);

namespace Kraite\Core\Concerns\Indicator;

use Illuminate\Database\Eloquent\Builder;

trait HasScopes
{
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('indicators.is_active', true);
    }

    public function scopeFromApi(Builder $query): Builder
    {
        return $query->where('indicators.is_computed', false);
    }

    public function scopeComputed(Builder $query): Builder
    {
        return $query->where('indicators.is_computed', true);
    }

    public function scopeConcluding(Builder $query): Builder
    {
        return $query->where('indicators.type', 'conclude-indicators');
    }

    public function scopeCanonical(Builder $query, string $canonical): Builder
    {
        return $query->where('indicators.canonical', $canonical);
    }
}
