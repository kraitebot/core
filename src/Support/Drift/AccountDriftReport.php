<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Drift;

use Kraite\Core\Models\Account;

/**
 * Aggregate drift report for a single account in a single audit cycle.
 *
 * `positions` covers every (symbol, direction) tuple seen on either side
 * (DB and/or exchange). `orphanOrders` collects exchange-side orders that
 * could not be paired to any DB position — typically manual orders or
 * stragglers from a closed position.
 */
final class AccountDriftReport
{
    /**
     * @param  PositionDriftReport[]  $positions
     * @param  array<int, array<string, mixed>>  $orphanOrders
     */
    public function __construct(
        public Account $account,
        public array $positions,
        public array $orphanOrders,
        public ?string $apiError = null,
    ) {}

    /**
     * Positions that disagree with the exchange and require sync-orders.
     *
     * @return PositionDriftReport[]
     */
    public function driftingPositions(): array
    {
        return array_values(array_filter(
            $this->positions,
            static fn (PositionDriftReport $p): bool => $p->needsHeal(),
        ));
    }
}
