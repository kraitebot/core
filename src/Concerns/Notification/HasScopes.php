<?php

declare(strict_types=1);

namespace Kraite\Core\Concerns\Notification;

use Illuminate\Database\Eloquent\Builder;

trait HasScopes
{
    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeByCanonical(Builder $query, string $canonical): Builder
    {
        return $query->where('canonical', $canonical);
    }
}
