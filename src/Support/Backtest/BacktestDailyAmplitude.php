<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Backtest;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Kraite\Core\Models\Candle;

final class BacktestDailyAmplitude
{
    /**
     * @param  Collection<int, Candle>  $candles
     * @return array{percentage: float, date: string|null}
     */
    public function calculate(Collection $candles): array
    {
        /** @var array<string, array{high: float, low: float}> $dailyRanges */
        $dailyRanges = [];

        foreach ($candles as $candle) {
            $low = (float) $candle->low;
            $high = (float) $candle->high;

            if (! is_finite($low) || ! is_finite($high) || $low <= 0.0 || $high < $low) {
                continue;
            }

            $rawUtcTime = $candle->getRawOriginal('candle_time_utc')
                ?? ($candle->getAttributes()['candle_time_utc'] ?? null);

            if ($rawUtcTime === null) {
                continue;
            }

            $date = Carbon::parse((string) $rawUtcTime, 'UTC')->utc()->toDateString();

            if (! isset($dailyRanges[$date])) {
                $dailyRanges[$date] = ['high' => $high, 'low' => $low];

                continue;
            }

            $dailyRanges[$date]['high'] = max($dailyRanges[$date]['high'], $high);
            $dailyRanges[$date]['low'] = min($dailyRanges[$date]['low'], $low);
        }

        $maximumPercentage = 0.0;
        $maximumDate = null;

        foreach ($dailyRanges as $date => $range) {
            $percentage = (($range['high'] - $range['low']) / $range['low']) * 100;

            if ($maximumDate === null || $percentage > $maximumPercentage) {
                $maximumPercentage = $percentage;
                $maximumDate = $date;
            }
        }

        return [
            'percentage' => round($maximumPercentage, 3),
            'date' => $maximumDate,
        ];
    }
}
