<?php

declare(strict_types=1);

namespace Kraite\Core\Trading\OrderLifecycle;

use Kraite\Core\Enums\OrderStatus;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\Math;

/**
 * Decides which workflow, if any, an order update must trigger.
 */
final readonly class OrderLifecycleCoordinator
{
    private const array CLOSE_TRIGGER_TYPES = ['PROFIT-LIMIT', 'PROFIT-MARKET', 'STOP-MARKET'];

    public function __construct(private OrderLifecycleDispatcher $dispatcher) {}

    /**
     * Route persisted exchange truth into one lifecycle action:
     *
     * - working orders may require external-modification correction;
     * - filled/triggered TP or SL orders close the position;
     * - terminal non-fill TP, SL, or LIMIT orders require replacement;
     * - filled LIMIT orders recalculate WAP;
     * - partially-filled LIMIT orders refresh position quantity only.
     *
     * Drift detection deliberately runs before the reference-status guard:
     * changing price or quantity does not necessarily change order status.
     */
    public function handleUpdated(Order $order): void
    {
        $position = $order->position;

        if ($position === null || ! in_array($position->status, $position->activeStatuses(), true)) {
            return;
        }

        $status = is_string($order->status)
            ? OrderStatus::tryFrom($order->status)
            : null;

        if ($status?->isWorkingOnExchange()) {
            $this->correctExternalModification($order, $position);
        }

        if ($order->status === $order->reference_status) {
            return;
        }

        if (in_array($order->type, self::CLOSE_TRIGGER_TYPES, true)) {
            if ($status?->closesPosition()) {
                $this->dispatcher->dispatchClosePosition($order, $position);
            } elseif ($status?->requiresReplacement()) {
                $this->dispatcher->dispatchPositionReplacement($order, $position);
            }

            return;
        }

        if ($order->type !== 'LIMIT' || $status === null) {
            return;
        }

        if ($status->requiresReplacement()) {
            $this->dispatcher->dispatchPositionReplacement($order, $position);

            return;
        }

        if ($status === OrderStatus::Filled) {
            $this->dispatcher->dispatchApplyWap($order, $position);

            return;
        }

        if ($status === OrderStatus::PartiallyFilled) {
            $this->dispatcher->dispatchSyncPositionQuantity($position);
        }
    }

    private function correctExternalModification(Order $order, Position $position): void
    {
        // Opening and WAP legitimately move order values before their reference
        // values catch up. Syncing is not skipped: it is the only window where
        // exchange-side user edits become visible locally.
        if (in_array($position->status, ['opening', 'waping'], true)) {
            return;
        }

        if ($order->reference_price === null && $order->reference_quantity === null) {
            return;
        }

        $hasPriceDrift = $order->reference_price !== null
            && $order->price !== null
            && ! Math::equal($order->price, $order->reference_price);

        $hasQuantityDrift = $order->reference_quantity !== null
            && $order->quantity !== null
            && ! Math::equal($order->quantity, $order->reference_quantity);

        if (! $hasPriceDrift && ! $hasQuantityDrift) {
            return;
        }

        $this->dispatcher->dispatchOrderCorrection(
            $order,
            $position,
            $hasPriceDrift,
            $hasQuantityDrift,
        );
    }
}
