<?php

declare(strict_types=1);

namespace Kraite\Core\Enums;

enum BacktestTimeframe: string
{
    case OneHour = '1h';
    case FourHours = '4h';
    case OneDay = '1d';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(
            static fn (self $timeframe): string => $timeframe->value,
            self::cases(),
        );
    }

    public function seconds(): int
    {
        return match ($this) {
            self::OneHour => 3_600,
            self::FourHours => 14_400,
            self::OneDay => 86_400,
        };
    }
}
