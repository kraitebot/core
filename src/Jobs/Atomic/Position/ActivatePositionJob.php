<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\Position;

use Kraite\Core\Abstracts\BaseQueueableJob;
use StepDispatcher\Exceptions\JustResolveException;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\Math;

/**
 * ActivatePositionJob (Atomic)
 *
 * Final validation step after all orders are placed.
 * Verifies order integrity before transitioning position to 'active'.
 *
 * Validation rules:
 * - Order count: 1 MARKET + N LIMIT + 1 TP + 1 SL
 * - MARKET: status=FILLED, reference_status=FILLED
 * - LIMIT/PROFIT-LIMIT/STOP-MARKET: status=NEW, reference_status=NEW
 * - All orders: reference_price == price, reference_quantity == quantity
 *   (Bitget TP/SL may have quantity=0, which is valid)
 *
 * Throws JustResolveException on any mismatch, which triggers
 * the resolve-exception handler without retry cycles.
 */
final class ActivatePositionJob extends BaseQueueableJob
{
    public Position $position;

    public function __construct(int $positionId)
    {
        $this->position = Position::findOrFail($positionId);
    }

    public function relatable()
    {
        return $this->position;
    }

    /**
     * Position must be in 'opening' status to be activated.
     */
    public function startOrFail(): bool
    {
        return $this->position->status === 'opening';
    }

    public function compute()
    {
        $position = $this->position;
        $orders = $position->orders()->get();

        // Expected: 1 MARKET + N LIMIT + 1 TP + 1 SL
        $expectedCount = 1 + $position->total_limit_orders + 2;
        $actualCount = $orders->count();

        if ($actualCount !== $expectedCount) {
            throw new JustResolveException(
                "Order count mismatch: expected {$expectedCount}, got {$actualCount}"
            );
        }

        // Validate MARKET order
        $marketOrders = $orders->where('type', 'MARKET');
        $this->validateMarketOrders($marketOrders);

        // Validate LIMIT orders
        $limitOrders = $orders->where('type', 'LIMIT');
        $this->validateLimitOrders($limitOrders, $position->total_limit_orders);

        // Validate TP order (PROFIT-LIMIT)
        $tpOrders = $orders->where('type', 'PROFIT-LIMIT');
        $this->validateTpOrders($tpOrders);

        // Validate SL order (STOP-MARKET)
        $slOrders = $orders->where('type', 'STOP-MARKET');
        $this->validateSlOrders($slOrders);

        return [
            'position_id' => $position->id,
            'total_orders' => $actualCount,
            'market_orders' => $marketOrders->count(),
            'limit_orders' => $limitOrders->count(),
            'tp_orders' => $tpOrders->count(),
            'sl_orders' => $slOrders->count(),
            'status' => 'validated',
        ];
    }

    public function complete(): void
    {
        $this->position->updateToActive();
    }

    /**
     * Validate MARKET orders.
     *
     * Expected: exactly 1 with status=FILLED, reference_status=FILLED
     *
     * @param  \Illuminate\Support\Collection<int, Order>  $orders
     */
    private function validateMarketOrders($orders): void
    {
        if ($orders->count() !== 1) {
            throw new JustResolveException(
                "Expected 1 MARKET order, got {$orders->count()}"
            );
        }

        $order = $orders->first();

        if ($order->status !== 'FILLED') {
            throw new JustResolveException(
                "MARKET order #{$order->id} status is '{$order->status}', expected 'FILLED'"
            );
        }

        if ($order->reference_status !== 'FILLED') {
            throw new JustResolveException(
                "MARKET order #{$order->id} reference_status is '{$order->reference_status}', expected 'FILLED'"
            );
        }

        $this->validateReferenceFields($order, 'MARKET');
    }

    /**
     * Validate LIMIT orders.
     *
     * Expected: exactly N with status=NEW, reference_status=NEW
     *
     * @param  \Illuminate\Support\Collection<int, Order>  $orders
     */
    private function validateLimitOrders($orders, int $expectedCount): void
    {
        if ($orders->count() !== $expectedCount) {
            throw new JustResolveException(
                "Expected {$expectedCount} LIMIT orders, got {$orders->count()}"
            );
        }

        foreach ($orders as $order) {
            if ($order->status !== 'NEW') {
                throw new JustResolveException(
                    "LIMIT order #{$order->id} status is '{$order->status}', expected 'NEW'"
                );
            }

            if ($order->reference_status !== 'NEW') {
                throw new JustResolveException(
                    "LIMIT order #{$order->id} reference_status is '{$order->reference_status}', expected 'NEW'"
                );
            }

            $this->validateReferenceFields($order, 'LIMIT');
        }
    }

    /**
     * Validate TP (PROFIT-LIMIT) orders.
     *
     * Expected: exactly 1 with status=NEW, reference_status=NEW
     *
     * @param  \Illuminate\Support\Collection<int, Order>  $orders
     */
    private function validateTpOrders($orders): void
    {
        if ($orders->count() !== 1) {
            throw new JustResolveException(
                "Expected 1 PROFIT-LIMIT (TP) order, got {$orders->count()}"
            );
        }

        $order = $orders->first();

        if ($order->status !== 'NEW') {
            throw new JustResolveException(
                "TP order #{$order->id} status is '{$order->status}', expected 'NEW'"
            );
        }

        if ($order->reference_status !== 'NEW') {
            throw new JustResolveException(
                "TP order #{$order->id} reference_status is '{$order->reference_status}', expected 'NEW'"
            );
        }

        $this->validateReferenceFields($order, 'TP');
    }

    /**
     * Validate SL (STOP-MARKET) orders.
     *
     * Expected: exactly 1 with status=NEW, reference_status=NEW
     *
     * @param  \Illuminate\Support\Collection<int, Order>  $orders
     */
    private function validateSlOrders($orders): void
    {
        if ($orders->count() !== 1) {
            throw new JustResolveException(
                "Expected 1 STOP-MARKET (SL) order, got {$orders->count()}"
            );
        }

        $order = $orders->first();

        if ($order->status !== 'NEW') {
            throw new JustResolveException(
                "SL order #{$order->id} status is '{$order->status}', expected 'NEW'"
            );
        }

        if ($order->reference_status !== 'NEW') {
            throw new JustResolveException(
                "SL order #{$order->id} reference_status is '{$order->reference_status}', expected 'NEW'"
            );
        }

        $this->validateReferenceFields($order, 'SL');
    }

    /**
     * Validate reference fields match current values.
     *
     * reference_price must equal price
     * reference_quantity must equal quantity (0 is valid for Bitget TP/SL)
     */
    private function validateReferenceFields(Order $order, string $orderType): void
    {
        // Price validation (both must match exactly)
        if (! Math::equal($order->reference_price ?? '0', $order->price ?? '0', 8)) {
            throw new JustResolveException(
                "{$orderType} order #{$order->id} price drift: reference_price={$order->reference_price}, price={$order->price}"
            );
        }

        // Quantity validation (both must match exactly, 0 is valid for Bitget TP/SL)
        if (! Math::equal($order->reference_quantity ?? '0', $order->quantity ?? '0', 8)) {
            throw new JustResolveException(
                "{$orderType} order #{$order->id} quantity drift: reference_quantity={$order->reference_quantity}, quantity={$order->quantity}"
            );
        }
    }
}
