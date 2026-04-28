<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Drift;

/**
 * Pair-result for a single (symbol, direction) tuple on an account.
 *
 * `status` describes the pair as a whole:
 *  - synced       : DB and exchange agree on every checked field.
 *  - drift        : at least one field on the position OR one of its orders
 *                   disagrees between DB and exchange.
 *  - db_only      : we hold an open position the exchange does not.
 *  - exchange_only: the exchange holds an open position we don't track.
 *  - transient    : DB position is mid-flight (opening/syncing/waping/...).
 *                   Drift checks are intentionally suppressed for these.
 */
final class PositionDriftReport
{
    public const STATUS_SYNCED = 'synced';

    public const STATUS_DRIFT = 'drift';

    public const STATUS_DB_ONLY = 'db_only';

    public const STATUS_EXCHANGE_ONLY = 'exchange_only';

    public const STATUS_TRANSIENT = 'transient';

    /**
     * @param  array<string, mixed>|null  $db
     * @param  array<string, mixed>|null  $exchange
     * @param  array<int, string>  $positionDriftFields
     * @param  OrderDriftReport[]  $orders
     */
    public function __construct(
        public string $symbol,
        public string $direction,
        public string $status,
        public ?int $positionId,
        public ?array $db,
        public ?array $exchange,
        public array $positionDriftFields,
        public array $orders,
    ) {}

    public function isHealthy(): bool
    {
        return $this->status === self::STATUS_SYNCED
            || $this->status === self::STATUS_TRANSIENT;
    }

    public function needsHeal(): bool
    {
        return in_array($this->status, [
            self::STATUS_DRIFT,
            self::STATUS_DB_ONLY,
            self::STATUS_EXCHANGE_ONLY,
        ], true);
    }

    /**
     * Order-level subset that disagrees with the exchange.
     *
     * @return OrderDriftReport[]
     */
    public function driftedOrders(): array
    {
        return array_values(array_filter(
            $this->orders,
            static fn (OrderDriftReport $o): bool => ! $o->isHealthy(),
        ));
    }
}
