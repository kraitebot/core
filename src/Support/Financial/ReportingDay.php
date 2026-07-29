<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Financial;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * The trader's day basis — which calendar day a UTC instant is reported
 * under, and where that day starts and ends.
 *
 * Exchanges let a trader choose this. Binance calls it "Change Basis" and
 * stores a fixed UTC offset (`UTC+2, 00:00`), not a named zone, so its
 * "Today's PnL" resets at that offset's midnight year-round. Kraite mirrors
 * the same shape, because the point of the setting is that both screens
 * agree on which trades belong to today.
 *
 * A fixed offset also sidesteps a production constraint: the MySQL timezone
 * tables are not loaded on the `kraite` host, so `CONVERT_TZ` returns NULL
 * there. Shifting by a known number of minutes always works.
 *
 * Stored timestamps stay UTC everywhere — this class never changes what is
 * persisted, only how a window of persisted instants is sliced into days.
 */
final class ReportingDay
{
    /** Real exchange bases run from UTC-12:00 to UTC+14:00. */
    private const MIN_OFFSET_MINUTES = -720;

    private const MAX_OFFSET_MINUTES = 840;

    /**
     * Bases that are not a whole hour but which real exchanges still offer:
     * UTC-09:30 Marquesas, -04:30, -03:30 Newfoundland, +03:30 Iran,
     * +04:30 Afghanistan, +05:30 India, +05:45 Nepal, +09:30 central
     * Australia, +12:45 and +13:45 Chatham.
     */
    private const FRACTIONAL_HOUR_OFFSETS = [-570, -270, -210, 210, 270, 330, 345, 570, 765, 825];

    public function __construct(public readonly int $offsetMinutes)
    {
        if ($offsetMinutes < self::MIN_OFFSET_MINUTES || $offsetMinutes > self::MAX_OFFSET_MINUTES) {
            throw new InvalidArgumentException(
                "UTC offset {$offsetMinutes} is outside the UTC-12:00 .. UTC+14:00 range."
            );
        }

        // Every real-world basis lands on a quarter hour (…, +05:30, +05:45).
        if ($offsetMinutes % 15 !== 0) {
            throw new InvalidArgumentException(
                "UTC offset {$offsetMinutes} is not a whole quarter of an hour."
            );
        }
    }

    /** UTC — the default basis for anything without a trader behind it. */
    public static function utc(): self
    {
        return new self(0);
    }

    /**
     * The basis a trader configured on their profile. A missing user, or one
     * predating the setting, reports on UTC — the behaviour every account had
     * before the basis existed.
     */
    public static function forUser(?object $user): self
    {
        $offset = $user?->utc_offset_minutes ?? 0;

        // A stored basis is a display preference, not money. If the column
        // ever holds something the value object rejects, report on UTC rather
        // than fail the trader's whole dashboard over it. Deliberate writes
        // still go through validation, and `new ReportingDay()` still throws
        // so a programming error stays loud.
        try {
            return new self((int) $offset);
        } catch (InvalidArgumentException) {
            return self::utc();
        }
    }

    /**
     * Every basis a trader may choose, as `minutes => label`, ordered west to
     * east. Single source of truth for both the profile picker and the
     * validation rule behind it, so the form can never offer a value the
     * request would reject.
     *
     * Whole hours across the full range, plus the fractional-hour bases.
     *
     * @return array<int, string>
     */
    public static function selectableOffsets(): array
    {
        $offsets = [];

        for ($minutes = self::MIN_OFFSET_MINUTES; $minutes <= self::MAX_OFFSET_MINUTES; $minutes += 60) {
            $offsets[$minutes] = (new self($minutes))->label();
        }

        foreach (self::FRACTIONAL_HOUR_OFFSETS as $minutes) {
            $offsets[$minutes] = (new self($minutes))->label();
        }

        ksort($offsets);

        return $offsets;
    }

    /** Human basis label in the shape exchanges print it: `UTC+02:00`. */
    public function label(): string
    {
        $sign = $this->offsetMinutes < 0 ? '-' : '+';
        $absolute = abs($this->offsetMinutes);

        return sprintf('UTC%s%02d:%02d', $sign, intdiv($absolute, 60), $absolute % 60);
    }

    /** The calendar day (YYYY-MM-DD) a UTC instant falls on for this trader. */
    public function dateOf(CarbonImmutable $utcInstant): string
    {
        return $this->toLocal($utcInstant)->toDateString();
    }

    /**
     * Midnight of the trader's day containing `$utcInstant`, expressed on
     * their own clock. Use this to enumerate trader days — walking UTC dates
     * instead would mislabel or skip a day whenever the two calendars differ.
     */
    public function startOfLocalDay(CarbonImmutable $utcInstant): CarbonImmutable
    {
        return $this->toLocal($utcInstant)->startOfDay();
    }

    /** UTC instant at which this trader's day containing `$utcInstant` began. */
    public function startOfDayUtc(CarbonImmutable $utcInstant): CarbonImmutable
    {
        return $this->toUtc($this->toLocal($utcInstant)->startOfDay());
    }

    /** UTC instant at which that same day ends (inclusive). */
    public function endOfDayUtc(CarbonImmutable $utcInstant): CarbonImmutable
    {
        return $this->toUtc($this->toLocal($utcInstant)->endOfDay());
    }

    /** UTC instant at which this trader's calendar month began. */
    public function startOfMonthUtc(CarbonImmutable $utcInstant): CarbonImmutable
    {
        return $this->toUtc($this->toLocal($utcInstant)->startOfMonth());
    }

    /** UTC instant at which this trader's calendar month ends (inclusive). */
    public function endOfMonthUtc(CarbonImmutable $utcInstant): CarbonImmutable
    {
        return $this->toUtc($this->toLocal($utcInstant)->endOfMonth());
    }

    /**
     * SQL expression grouping a UTC timestamp column into this trader's
     * calendar days. Driver-specific because production runs MySQL and the
     * suites run SQLite, and neither can read the other's date arithmetic.
     *
     * The column name is validated rather than quoted: these calculators
     * only ever group by their own hard-coded columns, so anything that is
     * not a plain identifier is a programming error, not user input.
     */
    public function dateExpression(string $column, string $driver): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column) !== 1) {
            throw new InvalidArgumentException("Refusing to group by non-identifier column [{$column}].");
        }

        if ($this->offsetMinutes === 0) {
            return "DATE({$column})";
        }

        return match ($driver) {
            'sqlite' => sprintf(
                "DATE(datetime(%s, '%s%d minutes'))",
                $column,
                $this->offsetMinutes < 0 ? '-' : '+',
                abs($this->offsetMinutes),
            ),
            default => sprintf(
                'DATE(DATE_ADD(%s, INTERVAL %d MINUTE))',
                $column,
                $this->offsetMinutes,
            ),
        };
    }

    private function toLocal(CarbonImmutable $utcInstant): CarbonImmutable
    {
        return $utcInstant->addMinutes($this->offsetMinutes);
    }

    private function toUtc(CarbonImmutable $localInstant): CarbonImmutable
    {
        return $localInstant->subMinutes($this->offsetMinutes);
    }
}
