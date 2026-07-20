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
 * | allow_other_positions=true  | only exchange positions matching a Kraite position closed within the match window are closed (leftovers); everything else is the user's and is ignored |
 *
 * Order ids attached to currently-open Kraite positions are never
 * flagged regardless of flag state — those are not orphans, they're
 * live working orders.
 */
final class OrphanReconciler
{
    /**
     * @param  array<int, string>  $exchangeOpenOrderIds  Exchange order ids currently visible on the exchange (limits + algos combined)
     * @param  array<int, string>  $exchangePositionKeys  Exchange position keys (`SYMBOL:DIRECTION` or `SYMBOL:BOTH` in one-way mode)
     * @param  array<int, string>  $kraiteOpenOrderIds  Exchange order ids of non-terminal Order rows on Kraite's `opened()` positions
     * @param  array<int, string>  $kraitePositionKeys  Position keys of Kraite's `opened()` positions
     * @param  array<int, string>  $kraiteRecentlyClosedOrderIds  Exchange order ids of Order rows linked to Kraite positions whose terminal transition happened within the configurable match window
     * @param  array<int, string>  $kraiteRecentlyClosedPositionKeys  Position keys of Kraite positions whose terminal transition happened within the match window — closable leftovers even on shared accounts
     * @param  bool  $hasInflightPositions  True when ANY local position on the account is in a transitional lifecycle state (`new` / `opening` / `cancelling` / `syncing`). When true, the order-orphan classification is skipped entirely — exchange orders that are mid-creation may not yet have their `exchange_order_id` written to the local Order row, so the diff would mis-classify legitimate working orders as orphans. Position-orphan classification remains active because position keys (`SYMBOL:DIRECTION`) are stable across the lifecycle.
     */
    public static function reconcile(
        array $exchangeOpenOrderIds,
        array $exchangePositionKeys,
        array $kraiteOpenOrderIds,
        array $kraitePositionKeys,
        array $kraiteRecentlyClosedOrderIds,
        bool $allowOtherOrders,
        bool $allowOtherPositions,
        bool $hasInflightPositions = false,
        array $kraiteRecentlyClosedPositionKeys = [],
    ): OrphanReport {
        $kraiteOpenSet = array_flip($kraiteOpenOrderIds);
        $kraiteRecentSet = array_flip($kraiteRecentlyClosedOrderIds);
        $kraitePositionSet = array_flip($kraitePositionKeys);
        $kraiteRecentPositionSet = array_flip($kraiteRecentlyClosedPositionKeys);

        $ordersToCancel = [];

        // In-flight guard: when a position is being opened, its limit
        // ladder is being placed on the exchange one rung at a time
        // and the local Order rows receive their `exchange_order_id`
        // AFTER each API ack. The orphan classifier compares exchange
        // ids against the local set — so during this window, exchange
        // orders that are perfectly legitimate working orders mis-
        // classify as orphans because the local row is mid-write.
        // Skip the whole order-cancel decision while any position on
        // the account is in transit. Watchdog runs every 5 minutes;
        // missing one tick during a ~30s position-open is acceptable.
        if (! $hasInflightPositions) {
            foreach ($exchangeOpenOrderIds as $orderId) {
                // Active working order — never an orphan.
                if (isset($kraiteOpenSet[$orderId])) {
                    continue;
                }

                if ($allowOtherOrders) {
                    // Cancel only when matched to a recently-closed
                    // Kraite position. Anything else is the operator's
                    // order.
                    if (isset($kraiteRecentSet[$orderId])) {
                        $ordersToCancel[] = $orderId;
                    }

                    continue;
                }

                // Kraite-exclusive: cancel everything not in our open
                // set.
                $ordersToCancel[] = $orderId;
            }
        }

        $positionsToClose = [];
        foreach ($exchangePositionKeys as $key) {
            if (isset($kraitePositionSet[$key])) {
                continue;
            }

            if ($allowOtherPositions) {
                // Shared account: close only provable Kraite leftovers
                // (locally closed within the window, still alive on the
                // exchange). Anything else is the user's position.
                if (isset($kraiteRecentPositionSet[$key])) {
                    $positionsToClose[] = $key;
                }

                continue;
            }

            // Kraite-exclusive: close everything unknown.
            $positionsToClose[] = $key;
        }

        return new OrphanReport(
            ordersToCancel: $ordersToCancel,
            positionsToClose: $positionsToClose,
        );
    }
}
