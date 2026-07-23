<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\Position;

use Exception;
use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\Math;
use Throwable;

/**
 * ActivatePositionJob (Atomic)
 *
 * Final validation step after all orders are placed.
 * Verifies order integrity before transitioning position to 'active'.
 *
 * Validation rules:
 * - Order count: 1 MARKET + N LIMIT + 1 TP + 1 SL
 * - MARKET: status=FILLED, reference_status=FILLED (no price/quantity
 *   reference check — fill values are exchange-determined)
 * - LIMIT/PROFIT-LIMIT/STOP-MARKET: status=NEW, reference_status=NEW
 * - LIMIT/TP/SL: reference_price == price, reference_quantity == quantity
 *   (Bitget TP/SL may have quantity=0, which is valid)
 *
 * Throws Exception on any mismatch, which triggers
 * the resolve-exception handler.
 */
final class ActivatePositionJob extends BaseQueueableJob
{
    public Position $position;

    public function __construct(int $positionId)
    {
        $this->position = Position::findOrFail($positionId);
    }

    public function relatable(): Position
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
            throw new Exception(
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

        $this->position->appLog(
            event: 'position_activated',
            message: 'Position activated — all orders confirmed',
        );
    }

    /**
     * Validate MARKET orders.
     *
     * Expected: exactly 1 with status=FILLED, reference_status=FILLED.
     *
     * Race-tolerant: a freshly-placed MARKET order can sit at
     * PARTIALLY_FILLED locally for a few hundred ms while the
     * user-data WS push for the FILLED transition is still in flight.
     * Treating that transient state as a hard error throws the
     * lifecycle into the cancel-cascade for a position that actually
     * filled correctly on the exchange — that was the 2026-05-03
     * #208 + #209 incident pattern. We now poll-with-timeout: if the
     * local row is non-terminal we hit `apiSync()` against the
     * exchange and re-read, up to a small bounded number of attempts
     * with a brief sleep in between. If the exchange-side truth is
     * FILLED, the row promotes and we proceed; if the exchange itself
     * still reports a non-terminal state after the retries, only then
     * do we surface the legitimate error.
     */
    private function validateMarketOrders($orders): void
    {
        if ($orders->count() !== 1) {
            throw new Exception(
                "Expected 1 MARKET order, got {$orders->count()}"
            );
        }

        $order = $orders->first();

        // Up to ~1.5s of poll-and-resync before declaring the order
        // legitimately not-filled. Each attempt hits the exchange via
        // `apiSync()` and refreshes the local row from the response.
        $maxAttempts = 3;
        $sleepMs = 500;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            if ($order->status === 'FILLED') {
                break;
            }

            try {
                $order->apiSync();
                $order->refresh();
            } catch (Throwable $e) {
                // apiSync failures are logged + ignored here — if the
                // exchange call dies we fall through to the throw
                // below, which is the right behaviour.
            }

            if ($order->status === 'FILLED') {
                break;
            }

            if ($attempt < $maxAttempts) {
                usleep($sleepMs * 1000);
            }
        }

        if ($order->status !== 'FILLED') {
            throw new Exception(
                "MARKET order #{$order->id} status is '{$order->status}', expected 'FILLED' (after {$maxAttempts} sync attempts)"
            );
        }

        if ($order->reference_status !== 'FILLED') {
            throw new Exception(
                "MARKET order #{$order->id} reference_status is '{$order->reference_status}', expected 'FILLED'"
            );
        }

        // No reference_price drift check on MARKET. The exchange determines
        // the fill price (slippage / multi-trade VWAP); local
        // `reference_price` is an informational snapshot from the first
        // sync, not a value we ever set or modify. Drift between the two
        // is expected on any non-trivial fill and must not gate activation
        // — Position #577 (TONUSDT, 2026-05-06) was cancelled with a
        // residual on the exchange because of a 0.00175% drift here.
    }

    /**
     * Validate LIMIT orders.
     *
     * Expected: exactly N with status=NEW, reference_status=NEW
     */
    private function validateLimitOrders($orders, int $expectedCount): void
    {
        if ($orders->count() !== $expectedCount) {
            throw new Exception(
                "Expected {$expectedCount} LIMIT orders, got {$orders->count()}"
            );
        }

        foreach ($orders as $order) {
            if ($order->status !== 'NEW') {
                throw new Exception(
                    "LIMIT order #{$order->id} status is '{$order->status}', expected 'NEW'"
                );
            }

            if ($order->reference_status !== 'NEW') {
                throw new Exception(
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
     */
    private function validateTpOrders($orders): void
    {
        if ($orders->count() !== 1) {
            throw new Exception(
                "Expected 1 PROFIT-LIMIT (TP) order, got {$orders->count()}"
            );
        }

        $order = $orders->first();

        if ($order->status !== 'NEW') {
            throw new Exception(
                "TP order #{$order->id} status is '{$order->status}', expected 'NEW'"
            );
        }

        if ($order->reference_status !== 'NEW') {
            throw new Exception(
                "TP order #{$order->id} reference_status is '{$order->reference_status}', expected 'NEW'"
            );
        }

        $this->validateReferenceFields($order, 'TP');
    }

    /**
     * Validate SL (STOP-MARKET) orders.
     *
     * Expected: exactly 1 with status=NEW, reference_status=NEW
     */
    private function validateSlOrders($orders): void
    {
        if ($orders->count() !== 1) {
            throw new Exception(
                "Expected 1 STOP-MARKET (SL) order, got {$orders->count()}"
            );
        }

        $order = $orders->first();

        if ($order->status !== 'NEW') {
            throw new Exception(
                "SL order #{$order->id} status is '{$order->status}', expected 'NEW'"
            );
        }

        if ($order->reference_status !== 'NEW') {
            throw new Exception(
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
            throw new Exception(
                "{$orderType} order #{$order->id} price drift: reference_price={$order->reference_price}, price={$order->price}"
            );
        }

        // Quantity validation (both must match exactly, 0 is valid for Bitget TP/SL)
        if (! Math::equal($order->reference_quantity ?? '0', $order->quantity ?? '0', 8)) {
            throw new Exception(
                "{$orderType} order #{$order->id} quantity drift: reference_quantity={$order->reference_quantity}, quantity={$order->quantity}"
            );
        }
    }
}
