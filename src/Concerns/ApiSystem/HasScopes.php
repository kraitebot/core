<?php

declare(strict_types=1);

namespace Kraite\Core\Concerns\ApiSystem;

use Illuminate\Database\Eloquent\Builder;

trait HasScopes
{
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('api_systems.is_active', true);
    }

    public function scopeExchange(Builder $query): Builder
    {
        return $query->where('api_systems.is_exchange', true);
    }

    public function scopeActiveExchange(Builder $query): Builder
    {
        return $query->active()->exchange();
    }

    public function scopeCanonical(Builder $query, string $canonical): Builder
    {
        return $query->where('api_systems.canonical', $canonical);
    }

    public function scopeExcludingCanonical(Builder $query, string $canonical): Builder
    {
        return $query->where('api_systems.canonical', '!=', $canonical);
    }
}
