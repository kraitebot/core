<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Throttlers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use Kraite\Core\Abstracts\BaseApiThrottler;
use Kraite\Core\Contracts\ClientLevelApiThrottler;
use UnexpectedValueException;

/**
 * TaapiThrottler
 *
 * Request-level limiter for TAAPI.IO. Reservations happen immediately before
 * every HTTP attempt because v2 may use more than one request per job.
 */
final class TaapiThrottler extends BaseApiThrottler implements ClientLevelApiThrottler
{
    private const RESERVATION_TTL_BUFFER_MS = 60_000;

    private const RESERVATION_LOCK_SECONDS = 10;

    private const RESERVATION_LOCK_WAIT_SECONDS = 5;

    public static function throttleRequest(): void
    {
        $waitMilliseconds = self::reserveRequest();

        if ($waitMilliseconds > 0) {
            Sleep::for($waitMilliseconds)->milliseconds();
        }
    }

    /**
     * TAAPI Rate Limits (configurable via config/kraite.php)
     *
     * @return array{requests_per_window: int, window_seconds: int, min_delay_between_requests_ms: int, safety_threshold: float}
     */
    protected static function getRateLimitConfig(): array
    {
        return [
            'requests_per_window' => config()->integer('kraite.throttlers.taapi.requests_per_window', 68),
            'window_seconds' => config()->integer('kraite.throttlers.taapi.window_seconds', 15),
            'min_delay_between_requests_ms' => config()->integer(
                'kraite.throttlers.taapi.min_delay_between_requests_ms',
                221,
            ),
            'safety_threshold' => self::safetyThreshold(),
        ];
    }

    protected static function getCacheKeyPrefix(): string
    {
        return 'taapi_throttler';
    }

    private static function reserveRequest(): int
    {
        $config = self::getRateLimitConfig();
        $windowMilliseconds = max(1, (int) $config['window_seconds']) * 1000;
        $effectiveLimit = max(
            1,
            (int) floor(
                max(1, (int) $config['requests_per_window'])
                * max(0.01, min(1.0, (float) $config['safety_threshold']))
            ),
        );
        $intervalMilliseconds = max(
            max(0, (int) $config['min_delay_between_requests_ms']),
            (int) ceil($windowMilliseconds / $effectiveLimit),
        );
        $reservationKey = self::getCacheKeyPrefix().':last_dispatch';

        $waitMilliseconds = Cache::lock(
            self::getCacheKeyPrefix().':request-reservation',
            self::RESERVATION_LOCK_SECONDS,
        )->block(
            self::RESERVATION_LOCK_WAIT_SECONDS,
            static function () use ($intervalMilliseconds, $reservationKey): int {
                $nowMilliseconds = self::currentTimeInMilliseconds();
                $cachedNextAvailableAt = Cache::get($reservationKey, $nowMilliseconds);

                if (is_string($cachedNextAvailableAt) && ctype_digit($cachedNextAvailableAt)) {
                    $cachedNextAvailableAt = (int) $cachedNextAvailableAt;
                }

                if (! is_int($cachedNextAvailableAt)) {
                    throw new UnexpectedValueException('TAAPI throttle reservation must be an integer timestamp.');
                }

                $reservedAt = max($nowMilliseconds, $cachedNextAvailableAt);
                $nextAvailableAt = $reservedAt + $intervalMilliseconds;
                $waitMilliseconds = $reservedAt - $nowMilliseconds;

                Cache::put(
                    $reservationKey,
                    $nextAvailableAt,
                    now()->addMilliseconds(
                        $waitMilliseconds + $intervalMilliseconds + self::RESERVATION_TTL_BUFFER_MS,
                    ),
                );

                return $waitMilliseconds;
            },
        );

        if (! is_int($waitMilliseconds)) {
            throw new UnexpectedValueException('TAAPI throttle reservation wait must be an integer.');
        }

        return $waitMilliseconds;
    }

    private static function safetyThreshold(): float
    {
        $value = config('kraite.throttlers.taapi.safety_threshold', 1.0);

        return is_float($value) || is_int($value) ? (float) $value : 1.0;
    }
}
