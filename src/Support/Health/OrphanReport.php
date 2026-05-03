<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Health;

/**
 * Immutable result of a single orphan reconcile pass.
 *
 * `ordersToCancel` is the list of exchange order ids that the
 * cleanup layer should cancel via `apiCancel*`. `positionsToClose`
 * is the list of `SYMBOL:DIRECTION` keys (or `SYMBOL:BOTH` for
 * one-way mode) that should be flat-closed via reduce-only MARKET.
 *
 * Both arrays are deduped + ordered as the reconciler emits them;
 * callers should not depend on a specific ordering.
 *
 * @phpstan-type OrderId string
 * @phpstan-type PositionKey string
 */
final class OrphanReport
{
    /**
     * @param  array<int, string>  $ordersToCancel
     * @param  array<int, string>  $positionsToClose
     */
    public function __construct(
        public readonly array $ordersToCancel,
        public readonly array $positionsToClose,
    ) {}

    public function isEmpty(): bool
    {
        return $this->ordersToCancel === []
            && $this->positionsToClose === [];
    }
}
