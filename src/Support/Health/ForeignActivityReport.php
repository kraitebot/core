<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Health;

/**
 * Result of a foreign-activity classification: the exchange orders and
 * positions that belong to the USER (neither live Kraite records nor
 * Kraite leftovers inside the match window).
 */
final readonly class ForeignActivityReport
{
    /**
     * @param  array<int, string>  $foreignOrderIds
     * @param  array<int, string>  $foreignPositionKeys
     */
    public function __construct(
        public array $foreignOrderIds,
        public array $foreignPositionKeys,
    ) {}

    public function hasAny(): bool
    {
        return $this->foreignOrderIds !== [] || $this->foreignPositionKeys !== [];
    }
}
