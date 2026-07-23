<?php

declare(strict_types=1);

namespace Kraite\Core\Concerns\Order;

use Illuminate\Database\Eloquent\Builder;
use Kraite\Core\Enums\OrderStatus;

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
    public function scopeSyncable(Builder $query): Builder
    {
        return $query->whereNotNull('orders.exchange_order_id')
            ->whereNotIn('orders.type', ['MARKET', 'MARKET-CANCEL'])
            ->whereIn('orders.status', OrderStatus::workingValues());
    }

    public function scopeCancellable(Builder $query): Builder
    {
        return $query->whereIn('type', ['LIMIT', 'STOP-LOSS', 'PROFIT-LIMIT', 'PROFIT-MARKET']);
    }

    public function scopeActiveOnExchange(Builder $query): Builder
    {
        return $query->whereNotNull('orders.exchange_order_id')
            ->whereIn('orders.status', OrderStatus::workingOrFilledValues());
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('orders.reference_status', OrderStatus::workingOrFilledValues());
    }

    public function scopeReferencedActive(Builder $query): Builder
    {
        return $query->whereIn('orders.reference_status', OrderStatus::workingOrFilledValues());
    }

    public function scopeCancelled(Builder $query): Builder
    {
        return $query->where('orders.reference_status', OrderStatus::Cancelled->value);
    }
}
