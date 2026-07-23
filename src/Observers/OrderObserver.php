<?php

declare(strict_types=1);

namespace Kraite\Core\Observers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Kraite\Core\Enums\OrderStatus;
use Kraite\Core\Exceptions\NonNotifiableException;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Trading\OrderLifecycle\OrderLifecycleCoordinator;

/**
 * Adapts Eloquent order events into order integrity and lifecycle policies.
 * Transactional lifecycle routing belongs to OrderLifecycleCoordinator.
 */
final readonly class OrderObserver
{
    public function __construct(private OrderLifecycleCoordinator $lifecycleCoordinator) {}

    public function creating(Order $model): true
    {
        $model->uuid ??= Str::uuid()->toString();
        $model->client_order_id ??= Str::uuid()->toString();

        // Conditional orders use exchange-specific endpoints.
        if ($model->type === 'STOP-MARKET' && $model->position?->account?->apiSystem !== null) {
            $canonical = $model->position->account->apiSystem->canonical;

            if (in_array($canonical, ['binance', 'kucoin', 'bitget', 'bybit'], strict: true)) {
                $model->is_algo = true;
            }
        }

        // Preserve the first-ever placement intent. Read raw attributes so
        // decimal accessors cannot strip forensic precision.
        $rawAttributes = $model->getAttributes();
        $rawPrice = $rawAttributes['price'] ?? null;

        if ($model->original_price === null && is_scalar($rawPrice)) {
            $model->original_price = (string) $rawPrice;
        }

        $rawQuantity = $rawAttributes['quantity'] ?? null;

        if ($model->original_quantity === null && is_scalar($rawQuantity)) {
            $model->original_quantity = (string) $rawQuantity;
        }

        return $this->enforceOrderLimits($model);
    }

    public function updating(Order $model): void
    {
        // TRIGGERED is immutable exchange truth. A later empty sync response
        // must not downgrade an order whose trigger already fired.
        if ($model->getOriginal('status') === OrderStatus::Triggered->value
            && $model->status !== OrderStatus::Triggered->value) {
            $model->status = OrderStatus::Triggered->value;
        }

        if ($model->status === OrderStatus::Filled->value && $model->filled_at === null) {
            $model->filled_at = now();
        }

        $this->protectImmutableAnchor($model, 'original_price');
        $this->protectImmutableAnchor($model, 'original_quantity');
    }

    public function updated(Order $model): void
    {
        $this->lifecycleCoordinator->handleUpdated($model);
    }

    private function protectImmutableAnchor(Order $model, string $column): void
    {
        if (! $model->isDirty($column)) {
            return;
        }

        $persisted = $model->getRawOriginal($column);

        if ($persisted === null) {
            return;
        }

        Log::channel('jobs')->warning('[ORDER-OBSERVER] reverted attempt to mutate immutable anchor', [
            'order_id' => $model->id,
            'column' => $column,
            'persisted' => $persisted,
            'attempted' => $model->getAttributes()[$column] ?? null,
        ]);

        $model->{$column} = $persisted;
    }

    /**
     * Reject an order when its position has no available slot for that type.
     */
    private function enforceOrderLimits(Order $model): true
    {
        $position = $model->position;

        if ($position === null) {
            return true;
        }

        // FILLED deliberately still occupies a slot. Only terminal non-fill
        // statuses release it.
        $activeQuery = Order::query()
            ->where('position_id', $position->id)
            ->whereNotIn('status', OrderStatus::terminalWithoutFillValues());

        $allowed = match ($model->type) {
            'STOP-MARKET' => $this->allowIfNoActiveExists($activeQuery, 'STOP-MARKET'),
            'MARKET' => $this->allowIfNoActiveExists($activeQuery, 'MARKET'),
            'MARKET-CANCEL' => $this->allowIfNoActiveExists($activeQuery, 'MARKET-CANCEL'),
            'PROFIT-LIMIT', 'PROFIT-MARKET' => $this->allowIfNoActiveProfitExists($activeQuery),
            'LIMIT' => $this->allowIfLimitNotExceeded($activeQuery, $position),
            default => true,
        };

        if ($allowed) {
            return true;
        }

        $position->modelLog('order_creation_rejected', [
            'type' => $model->type,
            'side' => $model->side,
            'position_side' => $model->position_side,
            'price' => $model->price,
            'quantity' => $model->quantity,
        ], message: "{$model->type} order rejected — limit exceeded for position #{$position->id}");

        $position->appLog(
            event: 'order_rejected',
            message: "{$model->type} order rejected — limit exceeded for position #{$position->id}",
            severity: 'warning',
            metadata: [
                'type' => $model->type,
                'side' => $model->side,
                'price' => $model->price,
                'quantity' => $model->quantity,
            ],
        );

        $group = in_array($model->type, ['PROFIT-LIMIT', 'PROFIT-MARKET'], true)
            ? 'PROFIT'
            : $model->type;

        throw new NonNotifiableException(
            "{$group} order creation blocked for position #{$position->id} — active limit exceeded",
        );
    }

    /**
     * @param  Builder<Order>  $query
     */
    private function allowIfNoActiveExists(Builder $query, string $type): bool
    {
        return ! (clone $query)->where('type', $type)->exists();
    }

    /**
     * @param  Builder<Order>  $query
     */
    private function allowIfNoActiveProfitExists(Builder $query): bool
    {
        return ! (clone $query)->whereIn('type', ['PROFIT-LIMIT', 'PROFIT-MARKET'])->exists();
    }

    /**
     * @param  Builder<Order>  $query
     */
    private function allowIfLimitNotExceeded(Builder $query, Position $position): bool
    {
        $activeCount = (clone $query)->where('type', 'LIMIT')->count();

        return $activeCount < $position->total_limit_orders;
    }
}
