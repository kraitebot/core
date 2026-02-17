<?php

declare(strict_types=1);

namespace Kraite\Core\Concerns\Order;

use Illuminate\Database\Eloquent\Builder;

trait HasScopes
{
    /**
     * Orders that can be synced from the exchange.
     *
     * Excludes MARKET orders (initial entry orders that execute immediately)
     * and requires exchange_order_id to exist.
     */
    public function scopeSyncable(Builder $query)
    {
        return $query->whereNotNull('orders.exchange_order_id')
            ->whereNotIn('orders.type', ['MARKET', 'MARKET-CANCEL']);
    }

    public function scopeCancellable(Builder $query)
    {
        return $query->whereIn('type', ['LIMIT', 'STOP-LOSS', 'PROFIT-LIMIT', 'PROFIT-MARKET']);
    }

    public function scopeActiveOnExchange($query)
    {
        return $query->whereNotNull('orders.exchange_order_id')
            ->whereIn('orders.status', ['NEW', 'FILLED', 'PARTIALLY_FILLED']);
    }

    public function scopeActive($query)
    {
        return $query->whereIn('orders.reference_status', ['NEW', 'FILLED', 'PARTIALLY_FILLED']);
    }

    public function scopeReferencedActive($query)
    {
        return $query->whereIn('orders.reference_status', ['NEW', 'FILLED', 'PARTIALLY_FILLED']);
    }

    public function scopeCancelled($query)
    {
        return $query->where('orders.reference_status', 'CANCELLED');
    }
}
