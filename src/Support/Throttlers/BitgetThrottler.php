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
 * Request-level limiter for Bitget Classic Futures v2 and Unified v3:
 * - Public endpoints: 20 requests per second per IP
 * - Private endpoint pacing follows each documented v2/v3 UID limit
 * - All endpoints combined: 6000 requests per minute per server IP
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
     * Public calls share the server-IP budget. Signed calls keep a one-way
     * API-key scope and a conservative server-shared private scope because
     * Bitget does not expose the UID needed to combine multiple API keys.
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
        $signed = $apiKey !== null;
        $requestsPerSecond = self::requestsPerSecond($endpoint, $signed);
        $scope = self::requestScope($apiKey);
        $endpointHash = hash('sha256', $endpoint);
        $reservations = [[
            sprintf('%s:request:%s:%s', self::getCacheKeyPrefix(), $scope, $endpointHash),
            self::intervalMilliseconds($requestsPerSecond),
        ]];

        if ($signed) {
            $reservations[] = [
                sprintf(
                    '%s:request:private-ip:%s:%s',
                    self::getCacheKeyPrefix(),
                    hash('sha256', self::getCurrentIp()),
                    $endpointHash
                ),
                self::intervalMilliseconds($requestsPerSecond),
            ];
        }

        $reservations[] = [
            sprintf(
                '%s:request:aggregate-ip:%s',
                self::getCacheKeyPrefix(),
                hash('sha256', self::getCurrentIp())
            ),
            self::aggregateIntervalMilliseconds(),
        ];

        $waitMs = 0;
        foreach ($reservations as [$reservationKey, $intervalMs]) {
            $waitMs = max($waitMs, self::reserve($reservationKey, $intervalMs));
        }

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
     * @param  int  $retryAfterSeconds  Seconds until ban lifts (default: five minutes)
     */
    public static function recordIpBan(int $retryAfterSeconds = 300): void
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

    private static function reserve(string $reservationKey, int $intervalMs): int
    {

        $reservation = Cache::lock(
            $reservationKey.':lock',
            self::RESERVATION_LOCK_SECONDS
        )->block(
            self::RESERVATION_LOCK_WAIT_SECONDS,
            static function () use ($reservationKey, $intervalMs): int {
                $nowMs = (int) round(now()->getPreciseTimestamp(3));
                $cachedNextAvailableAtMs = Cache::get($reservationKey, $nowMs);

                // The framework's Redis store persists numeric values raw
                // and returns them UNCAST, so a warm reservation key reads
                // back as a numeric string — normalise before the guard.
                if (is_string($cachedNextAvailableAtMs) && ctype_digit($cachedNextAvailableAtMs)) {
                    $cachedNextAvailableAtMs = (int) $cachedNextAvailableAtMs;
                }

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

        return $reservation;
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
            '/api/v3/account/adjust-account-mode' => self::integerConfig(
                'kraite.throttlers.bitget.uta_account_mode_requests_per_second',
                1
            ),
            '/api/v3/trade/close-positions',
            '/api/v3/trade/cancel-symbol-order' => self::integerConfig(
                'kraite.throttlers.bitget.uta_bulk_requests_per_second',
                5
            ),
            '/api/v3/account/assets',
            '/api/v3/account/info',
            '/api/v3/account/settings',
            '/api/v3/position/current-position',
            '/api/v3/position/history-position',
            '/api/v3/trade/order-info',
            '/api/v3/trade/unfilled-orders',
            '/api/v3/trade/history-orders',
            '/api/v3/trade/fills',
            '/api/v3/trade/unfilled-strategy-orders',
            '/api/v3/trade/history-strategy-orders' => self::integerConfig(
                'kraite.throttlers.bitget.uta_read_requests_per_second',
                20
            ),
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

    private static function aggregateIntervalMilliseconds(): int
    {
        $requestsPerMinute = max(
            1,
            self::integerConfig('kraite.throttlers.bitget.aggregate_requests_per_minute', 6_000)
        );
        $safetyThreshold = min(
            1.0,
            max(0.01, self::floatConfig('kraite.throttlers.bitget.safety_threshold', 0.85))
        );

        return (int) ceil(60_000 / ($requestsPerMinute * $safetyThreshold));
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
