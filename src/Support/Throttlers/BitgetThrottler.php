<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Throttlers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Kraite\Core\Abstracts\BaseApiThrottler;
use Kraite\Core\Contracts\ClientLevelApiThrottler;
use Kraite\Core\Models\Kraite;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

/**
 * BitgetThrottler
 *
 * Request-level limiter for Bitget Classic Futures V2:
 * - Public endpoints: 20 requests per second per IP
 * - Most private endpoints: 10 requests per second per UID
 * - Positions and account configuration: 5 requests per second per UID
 * - Flash close: 1 request per second per UID
 *
 * Reservations happen immediately before every HTTP attempt. This is
 * intentionally below jobs and recovery services: one job may make several
 * requests, and BaseApiClient may retry internally.
 */
final class BitgetThrottler extends BaseApiThrottler implements ClientLevelApiThrottler
{
    private const RESERVATION_TTL_BUFFER_MS = 60_000;

    private const RESERVATION_LOCK_SECONDS = 10;

    private const RESERVATION_LOCK_WAIT_SECONDS = 5;

    /**
     * Atomically reserve and pace one real Bitget HTTP attempt.
     *
     * Public calls share the server-IP budget. Signed calls share the UID
     * budget using a one-way hash of the API key, which is a safer UID proxy
     * than the local account ID when credentials are reused.
     */
    public static function throttleRequest(string $path, ?string $apiKey): void
    {
        if (self::isCurrentlyBanned()) {
            $banWaitSeconds = self::getSecondsUntilBanLifts();

            if ($banWaitSeconds <= 0) {
                throw new RuntimeException('Bitget request blocked because its IP-ban state is unavailable.');
            }

            Sleep::for($banWaitSeconds)->seconds();
        }

        $endpoint = Str::before($path, '?');
        $requestsPerSecond = self::requestsPerSecond($endpoint, $apiKey !== null);
        $intervalMs = self::intervalMilliseconds($requestsPerSecond);
        $scope = self::requestScope($apiKey);
        $reservationKey = sprintf(
            '%s:request:%s:%s',
            self::getCacheKeyPrefix(),
            $scope,
            hash('sha256', $endpoint)
        );

        $reservation = Cache::lock(
            $reservationKey.':lock',
            self::RESERVATION_LOCK_SECONDS
        )->block(
            self::RESERVATION_LOCK_WAIT_SECONDS,
            static function () use ($reservationKey, $intervalMs): int {
                $nowMs = (int) round(now()->getPreciseTimestamp(3));
                $cachedNextAvailableAtMs = Cache::get($reservationKey, $nowMs);

                if (! is_int($cachedNextAvailableAtMs)) {
                    throw new UnexpectedValueException('Bitget throttle reservation cache value must be an integer.');
                }

                $nextAvailableAtMs = max(
                    $nowMs,
                    $cachedNextAvailableAtMs
                );
                $newNextAvailableAtMs = $nextAvailableAtMs + $intervalMs;
                $waitMs = $nextAvailableAtMs - $nowMs;
                $ttlMs = $waitMs + $intervalMs + self::RESERVATION_TTL_BUFFER_MS;

                Cache::put(
                    $reservationKey,
                    $newNextAvailableAtMs,
                    now()->addMilliseconds($ttlMs)
                );

                return $waitMs;
            }
        );

        if (! is_int($reservation)) {
            throw new UnexpectedValueException('Bitget throttle reservation wait must be an integer.');
        }

        $waitMs = $reservation;

        if ($waitMs > 0) {
            Sleep::for($waitMs)->milliseconds();
        }
    }

    /** Pre-flight ban check for queue routing. Request pacing lives in the client. */
    public static function isSafeToDispatch(?int $accountId = null, int|string|null $stepId = null): int
    {
        if (self::isCurrentlyBanned()) {
            return max(5, self::getSecondsUntilBanLifts()) * 1000;
        }

        return 0;
    }

    /**
     * Record BitGet response headers.
     * BitGet doesn't provide specific rate limit headers,
     * but the timestamp remains useful for operational diagnostics.
     *
     * @param  ResponseInterface  $response  The API response
     * @param  int|null  $accountId  Optional account ID (not used here)
     */
    public static function recordResponseHeaders(ResponseInterface $response, ?int $accountId = null): void
    {
        try {
            $ip = self::getCurrentIp();

            Cache::put("bitget:{$ip}:last_request", now()->timestamp, 60);
        } catch (Throwable $e) {
            // Fail silently - don't break the application if Cache fails
        }
    }

    /**
     * Check if the current server IP is currently banned by BitGet (429 response).
     */
    public static function isCurrentlyBanned(): bool
    {
        try {
            $ip = self::getCurrentIp();
            $bannedUntil = Cache::get("bitget:{$ip}:banned_until");

            if ($bannedUntil === null) {
                return false;
            }

            return ! is_int($bannedUntil) || now()->getTimestamp() < $bannedUntil;
        } catch (Throwable $e) {
            // Fail CLOSED on cache failure — see BinanceThrottler for the
            // full rationale.
            Log::channel('jobs')->warning('[BitgetThrottler] isCurrentlyBanned cache failure — failing closed', [
                'error' => $e->getMessage(),
            ]);

            return true;
        }
    }

    /**
     * Record an IP ban in Cache when 429 errors occur.
     *
     * @param  int  $retryAfterSeconds  Seconds until ban lifts (default: 30 seconds)
     */
    public static function recordIpBan(int $retryAfterSeconds = 30): void
    {
        try {
            $ip = self::getCurrentIp();
            $expiresAt = now()->addSeconds($retryAfterSeconds);

            Cache::put(
                "bitget:{$ip}:banned_until",
                $expiresAt->timestamp,
                $retryAfterSeconds
            );
        } catch (Throwable $e) {
            // Fail silently - failing to record ban shouldn't break the app
        }
    }

    /** Compatibility fallback; active Bitget pacing uses throttleRequest(). */
    protected static function getRateLimitConfig(): array
    {
        return [
            'requests_per_window' => self::integerConfig(
                'kraite.throttlers.bitget.public_requests_per_second',
                20
            ),
            'window_seconds' => 1,
            'min_delay_between_requests_ms' => self::integerConfig(
                'kraite.throttlers.bitget.min_delay_ms',
                0
            ),
            'safety_threshold' => self::floatConfig(
                'kraite.throttlers.bitget.safety_threshold',
                0.85
            ),
        ];
    }

    protected static function getCacheKeyPrefix(): string
    {
        return 'bitget_throttler';
    }

    /**
     * Get seconds until ban lifts.
     */
    protected static function getSecondsUntilBanLifts(): int
    {
        try {
            $ip = self::getCurrentIp();
            $bannedUntil = Cache::get("bitget:{$ip}:banned_until");

            if (is_int($bannedUntil)) {
                return max(0, $bannedUntil - now()->getTimestamp());
            }

            return 0;
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * Get current server IP address.
     */
    protected static function getCurrentIp(): string
    {
        return Kraite::ip();
    }

    private static function requestsPerSecond(string $endpoint, bool $signed): int
    {
        if (! $signed) {
            return match ($endpoint) {
                '/api/v2/mix/market/query-position-lever' => self::integerConfig(
                    'kraite.throttlers.bitget.position_tier_requests_per_second',
                    10
                ),
                default => self::integerConfig(
                    'kraite.throttlers.bitget.public_requests_per_second',
                    20
                ),
            };
        }

        return match ($endpoint) {
            '/api/v2/mix/order/close-positions' => self::integerConfig(
                'kraite.throttlers.bitget.flash_close_requests_per_second',
                1
            ),
            '/api/v2/mix/position/all-position',
            '/api/v2/mix/account/set-leverage',
            '/api/v2/mix/account/set-margin-mode' => self::integerConfig(
                'kraite.throttlers.bitget.position_requests_per_second',
                5
            ),
            '/api/v2/mix/position/history-position' => self::integerConfig(
                'kraite.throttlers.bitget.position_history_requests_per_second',
                20
            ),
            default => self::integerConfig(
                'kraite.throttlers.bitget.private_requests_per_second',
                10
            ),
        };
    }

    private static function intervalMilliseconds(int $requestsPerSecond): int
    {
        $safeRequestsPerSecond = max(1, $requestsPerSecond);
        $safetyThreshold = min(
            1.0,
            max(0.01, self::floatConfig('kraite.throttlers.bitget.safety_threshold', 0.85))
        );
        $minimumDelayMs = max(
            0,
            self::integerConfig('kraite.throttlers.bitget.min_delay_ms', 0)
        );

        return max(
            $minimumDelayMs,
            (int) ceil(1000 / ($safeRequestsPerSecond * $safetyThreshold))
        );
    }

    private static function requestScope(?string $apiKey): string
    {
        if ($apiKey !== null && $apiKey !== '') {
            return 'uid:'.hash('sha256', $apiKey);
        }

        return 'ip:'.hash('sha256', self::getCurrentIp());
    }

    private static function integerConfig(string $key, int $default): int
    {
        $value = config($key, $default);

        return is_int($value) ? $value : $default;
    }

    private static function floatConfig(string $key, float $default): float
    {
        $value = config($key, $default);

        if (is_float($value)) {
            return $value;
        }

        return is_int($value) ? (float) $value : $default;
    }
}
