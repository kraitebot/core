<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Health;

/**
 * Pure-classification heart of the orphan-cleanup watchdog.
 *
 * Given an exchange-side snapshot + Kraite's local view + the per-
 * account `allow_other_*` flags, decides which exchange order ids
 * to cancel and which exchange positions to close.
 *
 * The behaviour matrix:
 *
 * |     orders flag      | order policy                                    |
 * |----------------------|-------------------------------------------------|
 * | allow_other_orders=false | every exchange order not in Kraite's open set is cancelled |
 * | allow_other_orders=true  | only exchange orders matched by client_order_id to a Kraite position closed within the configurable window are cancelled |
 *
 * |    positions flag       | position policy                              |
 * |-------------------------|----------------------------------------------|
 * | allow_other_positions=false | every exchange position not in Kraite's `opened()` set is closed |
 * | allow_other_positions=true  | exchange positions with no Kraite match are ignored (operator's responsibility) |
 *
 * Order ids attached to currently-open Kraite positions are never
 * flagged regardless of flag state — those are not orphans, they're
 * live working orders.
 */
final class OrphanReconciler
{
    /**
     * @param  array<int, string>  $exchangeOpenOrderIds       Exchange order ids currently visible on the exchange (limits + algos combined)
     * @param  array<int, string>  $exchangePositionKeys       Exchange position keys (`SYMBOL:DIRECTION` or `SYMBOL:BOTH` in one-way mode)
     * @param  array<int, string>  $kraiteOpenOrderIds         Exchange order ids of non-terminal Order rows on Kraite's `opened()` positions
     * @param  array<int, string>  $kraitePositionKeys         Position keys of Kraite's `opened()` positions
     * @param  array<int, string>  $kraiteRecentlyClosedOrderIds  Exchange order ids of Order rows linked to Kraite positions whose terminal transition happened within the configurable match window
     */
    public static function reconcile(
        array $exchangeOpenOrderIds,
        array $exchangePositionKeys,
        array $kraiteOpenOrderIds,
        array $kraitePositionKeys,
        array $kraiteRecentlyClosedOrderIds,
        bool $allowOtherOrders,
        bool $allowOtherPositions,
    ): OrphanReport {
        $kraiteOpenSet = array_flip($kraiteOpenOrderIds);
        $kraiteRecentSet = array_flip($kraiteRecentlyClosedOrderIds);
        $kraitePositionSet = array_flip($kraitePositionKeys);

        $ordersToCancel = [];
        foreach ($exchangeOpenOrderIds as $orderId) {
            // Active working order — never an orphan.
            if (isset($kraiteOpenSet[$orderId])) {
                continue;
            }

            if ($allowOtherOrders) {
                // Cancel only when matched to a recently-closed Kraite
                // position. Anything else is the operator's order.
                if (isset($kraiteRecentSet[$orderId])) {
                    $ordersToCancel[] = $orderId;
                }

                continue;
            }

            // Kraite-exclusive: cancel everything not in our open set.
            $ordersToCancel[] = $orderId;
        }

        $positionsToClose = [];
        if (! $allowOtherPositions) {
            foreach ($exchangePositionKeys as $key) {
                if (isset($kraitePositionSet[$key])) {
                    continue;
                }

                $positionsToClose[] = $key;
            }
        }

        return new OrphanReport(
            ordersToCancel: $ordersToCancel,
            positionsToClose: $positionsToClose,
        );
    }
}
