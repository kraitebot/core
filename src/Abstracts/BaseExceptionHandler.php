<?php

declare(strict_types=1);

namespace Kraite\Core\Abstracts;

use Exception;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Carbon;
use Kraite\Core\Models\Account;
use Kraite\Core\Support\ApiExceptionHandlers\AlternativeMeExceptionHandler;
use Kraite\Core\Support\ApiExceptionHandlers\BinanceExceptionHandler;
use Kraite\Core\Support\ApiExceptionHandlers\BitgetExceptionHandler;
use Kraite\Core\Support\ApiExceptionHandlers\BybitExceptionHandler;
use Kraite\Core\Support\ApiExceptionHandlers\CoinmarketCapExceptionHandler;
use Kraite\Core\Support\ApiExceptionHandlers\KucoinExceptionHandler;
use Kraite\Core\Support\ApiExceptionHandlers\TaapiExceptionHandler;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/*
 * BaseExceptionHandler
 *
 * • Abstract base for handling API-specific exceptions in a unified way.
 * • Provides default no-op implementations that can be overridden.
 * • Defines factory method `make()` to instantiate handler per API system.
 * • Enables retries, ignores, or custom resolution logic per provider.
 * • Used in APIable jobs to decide error handling and retry logic.
 */
abstract class BaseExceptionHandler
{
    public int $backoffSeconds = 10;

    public ?Account $account = null;

    // Returns the API system canonical name (e.g., 'taapi', 'coinmarketcap', 'binance')
    abstract public function getApiSystem(): string;

    // Check if exception is a recv window mismatch. Provided via ApiExceptionHelpers trait.
    abstract public function isRecvWindowMismatch(Throwable $exception): bool;

    // Check if exception is rate limited. Provided via ApiExceptionHelpers trait.
    abstract public function isRateLimited(Throwable $exception): bool;

    // Check if exception is forbidden (auth/permission). Provided via ApiExceptionHelpers trait.
    abstract public function isForbidden(Throwable $exception): bool;

    // Calculate when to retry after rate limit. Provided via ApiExceptionHelpers trait or overridden by child classes.
    abstract public function rateLimitUntil(RequestException $exception): Carbon;

    final public static function make(string $apiCanonical)
    {
        return match ($apiCanonical) {
            'binance' => new BinanceExceptionHandler,
            'bybit' => new BybitExceptionHandler,
            'kucoin' => new KucoinExceptionHandler,
            'bitget' => new BitgetExceptionHandler,
            'taapi' => new TaapiExceptionHandler,
            'alternativeme' => new AlternativeMeExceptionHandler,
            'coinmarketcap' => new CoinmarketCapExceptionHandler,
            default => throw new Exception("Unsupported Exception API Handler: {$apiCanonical}")
        };
    }

    /**
     * Health check to confirm handler is operational.
     * Default implementation returns true.
     * Override only if custom health checks are needed.
     */
    public function ping(): bool
    {
        return true;
    }

    /**
     * Record response headers for IP-based rate limiting coordination.
     * Called after every successful API response.
     * Default implementation is no-op (simple APIs don't need header tracking).
     * Complex APIs (Binance, Bybit) override to track rate limits in Redis.
     */
    public function recordResponseHeaders(ResponseInterface $response): void
    {
        // No-op by default - only complex APIs need this
    }

    /**
     * Check HTTP 200 responses for API-level errors and throw if found.
     * Some APIs (like Bybit) return HTTP 200 even for errors, with the actual
     * error code in the response body. This method allows those APIs to convert
     * such responses into exceptions that flow through normal error handling.
     *
     * Default implementation does nothing (most APIs use proper HTTP status codes).
     * Override in exchange-specific handlers that return errors in HTTP 200 responses.
     *
     * @param  ResponseInterface  $response  The HTTP 200 response to check
     * @param  RequestInterface  $request  The original request (needed for RequestException)
     *
     * @throws RequestException  If the response contains an API-level error
     */
    public function shouldThrowExceptionFromHTTP200(ResponseInterface $response, RequestInterface $request): void
    {
        // No-op by default - most APIs use proper HTTP status codes
    }

    /**
     * Check if the current server IP is currently banned by the API.
     * Default implementation returns false (simple APIs don't track IP bans).
     * Complex APIs (Binance, Bybit) override to check ban state in Redis.
     */
    public function isCurrentlyBanned(): bool
    {
        return false;
    }

    /**
     * Record an IP ban in shared state (Redis) when 418/429 errors occur.
     * Default implementation is no-op (simple APIs don't track IP bans).
     * Complex APIs (Binance, Bybit) override to store ban state in Redis.
     *
     * @param  int  $retryAfterSeconds  Seconds until ban expires
     */
    public function recordIpBan(int $retryAfterSeconds): void
    {
        // No-op by default - only complex APIs need this
    }

    /**
     * Pre-flight check before making an API request.
     * Default implementation returns true (simple APIs always allow requests).
     * Complex APIs (Binance, Bybit) override to check:
     * - IP ban status
     * - Minimum delay since last request
     * - Rate limit proximity (>80%)
     */
    public function isSafeToMakeRequest(): bool
    {
        return true;
    }

    // Eager loads an account for later use.
    final public function withAccount(Account $account)
    {
        $this->account = $account;

        return $this;
    }

    /**
     * Get the current server's IP address.
     * Used for IP-based rate limiting and ban coordination.
     */
    protected function getCurrentIp(): string
    {
        return \Kraite\Core\Models\Engine::ip();
    }
}
