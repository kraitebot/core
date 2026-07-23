<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Backtest;

use Carbon\Carbon;
use Kraite\Core\Enums\BacktestTimeframe;

final class BacktestHistoryWindow
{
    /**
     * @param  array<string, mixed>  $coverage
     */
    public static function covers(
        array $coverage,
        string $timeframe,
        int $maxMonths,
        ?int $requestedSinceTimestamp = null,
    ): bool {
        $earliest = $coverage['earliest'] ?? null;
        $firstRequiredCandle = self::requiredSinceTimestamp(
            $timeframe,
            $maxMonths,
            $requestedSinceTimestamp,
        );

        if ($firstRequiredCandle === null || ! is_string($earliest) || $earliest === '') {
            return false;
        }

        return Carbon::parse($earliest, 'UTC')->getTimestamp() <= $firstRequiredCandle;
    }

    public static function requiredSinceTimestamp(
        string $timeframe,
        int $maxMonths,
        ?int $requestedSinceTimestamp = null,
    ): ?int {
        $backtestTimeframe = BacktestTimeframe::tryFrom($timeframe);

        if ($backtestTimeframe === null) {
            return null;
        }

        $requiredSinceTimestamp = $requestedSinceTimestamp
            ?? Carbon::now('UTC')->startOfMonth()->subMonths($maxMonths)->getTimestamp();
        $intervalSeconds = $backtestTimeframe->seconds();

        return intdiv(
            $requiredSinceTimestamp + $intervalSeconds - 1,
            $intervalSeconds,
        ) * $intervalSeconds;
    }
}
