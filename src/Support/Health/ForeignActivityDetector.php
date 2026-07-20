<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Health;

/**
 * Pure classifier answering: "does this exchange account carry
 * positions/orders the USER placed themselves?"
 *
 * Foreign = present on the exchange but neither attached to a live
 * Kraite record nor identifiable as a Kraite leftover (order id or
 * position key of a Kraite position closed within the match window).
 * The verdict drives the automatic sync of the account's
 * allow_other_positions / allow_other_orders protection flags.
 *
 * No DB queries, no API calls — pure decision logic.
 */
final class ForeignActivityDetector
{
    /**
     * @param  array<int, string>  $exchangeOpenOrderIds
     * @param  array<int, string>  $exchangePositionKeys
     * @param  array<int, string>  $kraiteOpenOrderIds
     * @param  array<int, string>  $kraitePositionKeys
     * @param  array<int, string>  $kraiteRecentlyClosedOrderIds
     * @param  array<int, string>  $kraiteRecentlyClosedPositionKeys
     */
    public static function detect(
        array $exchangeOpenOrderIds,
        array $exchangePositionKeys,
        array $kraiteOpenOrderIds,
        array $kraitePositionKeys,
        array $kraiteRecentlyClosedOrderIds,
        array $kraiteRecentlyClosedPositionKeys,
    ): ForeignActivityReport {
        $knownOrders = array_flip([...$kraiteOpenOrderIds, ...$kraiteRecentlyClosedOrderIds]);
        $knownPositions = array_flip([...$kraitePositionKeys, ...$kraiteRecentlyClosedPositionKeys]);

        $foreignOrders = array_values(array_filter(
            $exchangeOpenOrderIds,
            static fn (string $id): bool => ! isset($knownOrders[$id]),
        ));

        $foreignPositions = array_values(array_filter(
            $exchangePositionKeys,
            static fn (string $key): bool => ! isset($knownPositions[$key]),
        ));

        return new ForeignActivityReport(
            foreignOrderIds: $foreignOrders,
            foreignPositionKeys: $foreignPositions,
        );
    }
}
