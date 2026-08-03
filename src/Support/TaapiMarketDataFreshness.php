<?php

declare(strict_types=1);

namespace Kraite\Core\Support;

final class TaapiMarketDataFreshness
{
    /**
     * @param  array<string, array{result: mixed}>  $indicatorData
     * @return array{is_fresh: bool, latest_timestamp: int|null, minimum_timestamp: int|null}
     */
    public static function fromIndicatorData(array $indicatorData, string $timeframe): array
    {
        $candleComparison = $indicatorData['candle-comparison']['result'] ?? null;

        return self::fromTimestamps(
            is_array($candleComparison) ? ($candleComparison['timestamp'] ?? null) : null,
            $timeframe,
        );
    }

    /**
     * @return array{is_fresh: bool, latest_timestamp: int|null, minimum_timestamp: int|null}
     */
    public static function unavailable(): array
    {
        return [
            'is_fresh' => false,
            'latest_timestamp' => null,
            'minimum_timestamp' => null,
        ];
    }

    /**
     * @return array{is_fresh: bool, latest_timestamp: int|null, minimum_timestamp: int|null}
     */
    private static function fromTimestamps(mixed $rawTimestamps, string $timeframe): array
    {
        $latestTimestamp = self::latestTimestamp($rawTimestamps);
        $intervalSeconds = self::timeframeSeconds($timeframe);

        if ($intervalSeconds === null) {
            return [
                'is_fresh' => false,
                'latest_timestamp' => $latestTimestamp,
                'minimum_timestamp' => null,
            ];
        }

        if ($latestTimestamp === null) {
            return self::unavailable();
        }

        $currentCandleTimestamp = intdiv(now()->getTimestamp(), $intervalSeconds) * $intervalSeconds;

        return [
            'is_fresh' => $latestTimestamp === $currentCandleTimestamp,
            'latest_timestamp' => $latestTimestamp,
            'minimum_timestamp' => $currentCandleTimestamp,
        ];
    }

    private static function latestTimestamp(mixed $rawTimestamps): ?int
    {
        $values = is_array($rawTimestamps) ? $rawTimestamps : [$rawTimestamps];
        $timestamps = [];

        foreach ($values as $value) {
            if (! is_numeric($value)) {
                continue;
            }

            $timestamp = (int) $value;

            if ($timestamp >= 1_000_000_000_000_000) {
                $timestamp = intdiv($timestamp, 1_000_000);
            } elseif ($timestamp >= 1_000_000_000_000) {
                $timestamp = intdiv($timestamp, 1000);
            }

            if ($timestamp > 0) {
                $timestamps[] = $timestamp;
            }
        }

        return $timestamps === [] ? null : max($timestamps);
    }

    private static function timeframeSeconds(string $timeframe): ?int
    {
        if (preg_match('/^(\d+)([smhdw])$/i', $timeframe, $matches) !== 1) {
            return null;
        }

        $quantity = (int) $matches[1];

        if ($quantity < 1) {
            return null;
        }

        $unitSeconds = [
            's' => 1,
            'm' => 60,
            'h' => 3600,
            'd' => 86_400,
            'w' => 604_800,
        ][mb_strtolower($matches[2])] ?? null;

        if ($unitSeconds === null) {
            return null;
        }

        return $quantity * $unitSeconds;
    }
}
