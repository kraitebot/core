<?php

declare(strict_types=1);

namespace Kraite\Core\Concerns\Order;

use Illuminate\Database\Eloquent\Builder;

trait HasScopes
{
    /**
     * Orders that can be synced from the exchange.
     *
     * Only working orders can still change exchange state. Terminal orders
     * are immutable and some exchanges stop returning them after a short
     * retention window, so repeatedly querying them creates permanent
     * "order not found" traffic without providing new truth.
     */
    public function scopeSyncable(Builder $query)
    {
        return $query->whereNotNull('orders.exchange_order_id')
            ->whereNotIn('orders.type', ['MARKET', 'MARKET-CANCEL'])
            ->whereIn('orders.status', ['NEW', 'PARTIALLY_FILLED']);
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
