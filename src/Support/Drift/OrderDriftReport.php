<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Drift;

/**
 * Pair-result for a single order on a single position.
 *
 * `status` is one of: synced, drift, db_only, exchange_only.
 * `driftFields` lists the field names that disagree between DB and exchange
 * (only populated when `status === 'drift'`).
 */
final class OrderDriftReport
{
    public const STATUS_SYNCED = 'synced';

    public const STATUS_DRIFT = 'drift';

    public const STATUS_DB_ONLY = 'db_only';

    public const STATUS_EXCHANGE_ONLY = 'exchange_only';

    /**
     * @param  array<string, mixed>|null  $db
     * @param  array<string, mixed>|null  $exchange
     * @param  array<int, string>  $driftFields
     */
    public function __construct(
        public string $status,
        public ?array $db,
        public ?array $exchange,
        public array $driftFields,
    ) {}

    public function isHealthy(): bool
    {
        return $this->status === self::STATUS_SYNCED;
    }
}
