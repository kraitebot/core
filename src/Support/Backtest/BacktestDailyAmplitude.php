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
        /** @var array<string, array{high: float, low: float, close: float|null, close_timestamp: int|null}> $dailyRanges */
        $dailyRanges = [];

        foreach ($candles as $candle) {
            $low = (float) $candle->low;
            $high = (float) $candle->high;
            $close = (float) $candle->close;

            if (! is_finite($low) || ! is_finite($high) || $low <= 0.0 || $high < $low) {
                continue;
            }

            $rawUtcTime = $candle->getRawOriginal('candle_time_utc')
                ?? ($candle->getAttributes()['candle_time_utc'] ?? null);

            if ($rawUtcTime === null) {
                continue;
            }

            $utcTime = Carbon::parse((string) $rawUtcTime, 'UTC')->utc();
            $date = $utcTime->toDateString();
            $timestamp = $utcTime->getTimestamp();
            $validClose = is_finite($close) && $close > 0.0 ? $close : null;

            if (! isset($dailyRanges[$date])) {
                $dailyRanges[$date] = [
                    'high' => $high,
                    'low' => $low,
                    'close' => $validClose,
                    'close_timestamp' => $validClose !== null ? $timestamp : null,
                ];

                continue;
            }

            $dailyRanges[$date]['high'] = max($dailyRanges[$date]['high'], $high);
            $dailyRanges[$date]['low'] = min($dailyRanges[$date]['low'], $low);

            if ($validClose !== null && ($dailyRanges[$date]['close_timestamp'] === null || $timestamp > $dailyRanges[$date]['close_timestamp'])) {
                $dailyRanges[$date]['close'] = $validClose;
                $dailyRanges[$date]['close_timestamp'] = $timestamp;
            }
        }

        ksort($dailyRanges);

        $maximumPercentage = 0.0;
        $maximumDate = null;
        $previousClose = null;

        foreach ($dailyRanges as $date => $range) {
            if ($previousClose !== null) {
                $percentage = (($range['high'] - $range['low']) / $previousClose) * 100;

                if ($maximumDate === null || $percentage > $maximumPercentage) {
                    $maximumPercentage = $percentage;
                    $maximumDate = $date;
                }
            }

            $previousClose = $range['close'];
        }

        return [
            'percentage' => floor($maximumPercentage * 100) / 100,
            'date' => $maximumDate,
        ];
    }
}
