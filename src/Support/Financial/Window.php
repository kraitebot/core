<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Financial;

use Carbon\CarbonImmutable;

/**
 * Inclusive [start, end] datetime window used by every Financial query.
 * Immutable, validated at construction (start ≤ end). Use named
 * constructors for the common cases — `new Window(...)` only when you
 * already have two CarbonImmutable instances.
 */
final class Window
{
    public function __construct(
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
    ) {
        if ($end->lt($start)) {
            throw new \InvalidArgumentException('Window end must be ≥ start.');
        }
    }

    public static function between(CarbonImmutable $start, CarbonImmutable $end): self
    {
        return new self($start, $end);
    }

    /** Calendar-month window: [first day 00:00:00, last day 23:59:59]. */
    public static function thisMonth(?CarbonImmutable $now = null): self
    {
        $now ??= CarbonImmutable::now();

        return new self($now->startOfMonth(), $now->endOfMonth());
    }

    /** Today only: [today 00:00:00, today 23:59:59]. */
    public static function today(?CarbonImmutable $now = null): self
    {
        $now ??= CarbonImmutable::now();

        return new self($now->startOfDay(), $now->endOfDay());
    }

    /** Trailing window: last N calendar days ending today. */
    public static function lastDays(int $days, ?CarbonImmutable $now = null): self
    {
        if ($days < 1) {
            throw new \InvalidArgumentException('lastDays() needs a positive day count.');
        }

        $now ??= CarbonImmutable::now();

        return new self(
            $now->subDays($days - 1)->startOfDay(),
            $now->endOfDay(),
        );
    }

    /** Returns a new window clamped so start ≥ floor. End is untouched. */
    public function flooredAt(CarbonImmutable $floor): self
    {
        return $this->start->lt($floor) ? new self($floor, $this->end) : $this;
    }
}
