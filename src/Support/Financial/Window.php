<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Financial;

use Carbon\CarbonImmutable;

/**
 * Inclusive [start, end] datetime window used by every Financial query.
 * Immutable, validated at construction (start ≤ end). Use named
 * constructors for the common cases — `new Window(...)` only when you
 * already have two CarbonImmutable instances.
 *
 * Bounds are always UTC, because that is what the database stores. The
 * named constructors take an optional `ReportingDay` so "today" and "this
 * month" can mean the trader's day rather than UTC's — a UTC+2 trader's
 * today starts at 22:00 UTC the evening before.
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

    /**
     * Calendar-month window on the trader's day basis, expressed in UTC.
     * Defaults to UTC so a caller with no trader behind it — public stats,
     * a system report — keeps the plain UTC month.
     */
    public static function thisMonth(?CarbonImmutable $now = null, ?ReportingDay $basis = null): self
    {
        $now ??= CarbonImmutable::now();
        $basis ??= ReportingDay::utc();

        return new self($basis->startOfMonthUtc($now), $basis->endOfMonthUtc($now));
    }

    /**
     * The trader's current day, expressed in UTC. On a UTC+2 basis at 23:38
     * UTC this is the day that opened at 22:00 UTC — the same span the
     * exchange is counting under "Today".
     */
    public static function today(?CarbonImmutable $now = null, ?ReportingDay $basis = null): self
    {
        $now ??= CarbonImmutable::now();
        $basis ??= ReportingDay::utc();

        return new self($basis->startOfDayUtc($now), $basis->endOfDayUtc($now));
    }

    /** Trailing window: last N of the trader's days, ending with today. */
    public static function lastDays(int $days, ?CarbonImmutable $now = null, ?ReportingDay $basis = null): self
    {
        if ($days < 1) {
            throw new \InvalidArgumentException('lastDays() needs a positive day count.');
        }

        $now ??= CarbonImmutable::now();
        $basis ??= ReportingDay::utc();

        return new self(
            $basis->startOfDayUtc($now)->subDays($days - 1),
            $basis->endOfDayUtc($now),
        );
    }

    /** Returns a new window clamped so start ≥ floor. End is untouched. */
    public function flooredAt(CarbonImmutable $floor): self
    {
        return $this->start->lt($floor) ? new self($floor, $this->end) : $this;
    }
}
