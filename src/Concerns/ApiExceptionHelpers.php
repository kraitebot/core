<?php

declare(strict_types=1);

namespace Kraite\Core\Concerns;

use Carbon\CarbonInterface;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ForbiddenHostname;
use Kraite\Core\Models\Kraite;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * ApiExceptionHelpers (generic)
 *
 * • Exchange-agnostic utilities for classifying/handling HTTP/API exceptions.
 * • No throttling, Redis, or clock/recvWindow logic here (handled elsewhere).
 */
trait ApiExceptionHelpers
{
    /**
     * ----------------
     * Core classifiers
     * ----------------
     */
    public function isRecvWindowMismatch(Throwable $exception): bool
    {
        return $this->containsHttpExceptionIn($exception, $this->recvWindowMismatchedHttpCodes);
    }

    public function isRateLimited(Throwable $exception): bool
    {
        return $this->containsHttpExceptionIn($exception, $this->serverRateLimitedHttpCodes);
    }

    /**
     * Generic forbidden check (uses serverForbiddenHttpCodes).
     * For more specific classification, use:
     * - isIpNotWhitelisted(): Case 1 - User forgot to whitelist IP
     * - isIpRateLimited(): Case 2 - Temporary rate limit ban
     * - isIpBanned(): Case 3 - Permanent ban for ALL accounts
     * - isAccountBlocked(): Case 4 - Account-specific API key issue
     */
    public function isForbidden(Throwable $exception): bool
    {
        return $this->containsHttpExceptionIn($exception, $this->serverForbiddenHttpCodes);
    }

    /**
     * ----------------------------------------
     * Specific IP/Account classifiers (4 cases)
     * ----------------------------------------
     * These methods should be overridden by exchange-specific handlers.
     * Default implementations return false (not detected).
     */

    /**
     * Case 1: IP not whitelisted by user.
     * User forgot to add server IP to their API key whitelist.
     * Recovery: User adds IP to exchange whitelist.
     */
    public function isIpNotWhitelisted(Throwable $exception): bool
    {
        // Default: not detected. Override in exchange-specific handlers.
        return false;
    }

    /**
     * API key exists and authenticates but lacks a permission scope the
     * call needs (e.g. a Bitget unified key without "UTA management
     * (read)"). Recovery: user edits the key's permissions on the
     * exchange.
     */
    public function isMissingPermissions(Throwable $exception): bool
    {
        // Default: not detected. Override in exchange-specific handlers.
        return false;
    }

    /**
     * Case 2: IP temporarily rate-limited.
     * Server hit rate limits and is temporarily blocked.
     * Recovery: Auto-recovers after Retry-After period.
     */
    public function isIpRateLimited(Throwable $exception): bool
    {
        // Default: not detected. Override in exchange-specific handlers.
        return false;
    }

    /**
     * Case 3: IP permanently banned.
     * Server is permanently banned from exchange for ALL accounts.
     * Recovery: Manual - contact exchange support.
     */
    public function isIpBanned(Throwable $exception): bool
    {
        // Default: not detected. Override in exchange-specific handlers.
        return false;
    }

    /**
     * Case 4: Account blocked.
     * Specific account's API key is revoked, disabled, or has permission issues.
     * Recovery: User regenerates API key on exchange.
     */
    public function isAccountBlocked(Throwable $exception): bool
    {
        // Default: not detected. Override in exchange-specific handlers.
        return false;
    }

    /**
     * ----------------------------------------
     * Forbid methods for 4 blocking cases
     * ----------------------------------------
     */

    /**
     * Case 1: IP not whitelisted by user.
     * User forgot to add server IP to their API key whitelist.
     * Creates account-specific record, notifies the USER (not admin).
     * Auto-expires after 24 hours to allow retry in case user fixed it.
     */
    public function forbidIpNotWhitelisted(Throwable $exception): void
    {
        $errorData = $this->extractHttpErrorCodes($exception);

        // IP-not-whitelisted is user-action-required: the operator must
        // add this IP on the exchange's whitelist. Stamped with a 1-hour
        // TTL so the worker-IP rotation engine has a natural re-probe
        // cadence — without an expiry, a single mis-whitelisted IP locks
        // out forever until an operator manually clears the row. When the
        // user fixes the whitelist, the next successful authenticated
        // call inside this hour self-heals the record via the success-
        // path cleanup; if the issue is unresolved, the next failed call
        // simply refreshes the TTL via updateOrCreate (no spam notification).
        $this->createForbiddenRecord(
            type: ForbiddenHostname::TYPE_IP_NOT_WHITELISTED,
            accountId: $this->account->id, // Account-specific
            forbiddenUntil: now()->addHour(),
            errorCode: (string) ($errorData['status_code'] ?? ''),
            errorMessage: $errorData['message'] ?? null
        );
    }

    /**
     * Case 2: IP temporarily rate-limited.
     * Server hit rate limits and is temporarily blocked.
     * Creates system-wide record (affects all accounts on this IP).
     */
    public function forbidIpRateLimited(Throwable $exception): void
    {
        $errorData = $this->extractHttpErrorCodes($exception);

        // Calculate when the ban expires
        $forbiddenUntil = $exception instanceof RequestException
            ? $this->rateLimitUntil($exception)
            : now()->addMinutes(10); // Default 10 minutes for Bybit

        $this->createForbiddenRecord(
            type: ForbiddenHostname::TYPE_IP_RATE_LIMITED,
            accountId: null, // System-wide (all accounts affected)
            forbiddenUntil: $forbiddenUntil,
            errorCode: (string) ($errorData['status_code'] ?? ''),
            errorMessage: $errorData['message'] ?? null
        );
    }

    /**
     * Case 3: IP permanently banned.
     * Server is permanently banned from exchange for ALL accounts.
     * Creates system-wide record, notifies admin.
     */
    public function forbidIpBanned(Throwable $exception): void
    {
        $errorData = $this->extractHttpErrorCodes($exception);

        $this->createForbiddenRecord(
            type: ForbiddenHostname::TYPE_IP_BANNED,
            accountId: null, // System-wide (all accounts affected)
            forbiddenUntil: null, // Permanent
            errorCode: (string) ($errorData['status_code'] ?? ''),
            errorMessage: $errorData['message'] ?? null
        );
    }

    /**
     * Case 4: Account blocked.
     * Specific account's API key is revoked, disabled, or has permission issues.
     * Creates account-specific record, notifies the USER.
     * Auto-expires after 24 hours to allow retry in case user fixed it.
     */
    public function forbidAccountBlocked(Throwable $exception): void
    {
        $errorData = $this->extractHttpErrorCodes($exception);

        // Account-blocked = invalid / revoked / disabled API key. Stamped
        // with a 1-hour TTL so the worker-IP rotation engine has a natural
        // re-probe cadence and so an account that gets re-credentialled is
        // automatically unblocked within an hour without needing the
        // success-path self-heal to fire (which it can't on a permanently
        // banned IP). Duplicate detections refresh the TTL via
        // updateOrCreate without a fresh user notification.
        $this->createForbiddenRecord(
            type: ForbiddenHostname::TYPE_ACCOUNT_BLOCKED,
            accountId: $this->account->id, // Account-specific
            forbiddenUntil: now()->addHour(),
            errorCode: (string) ($errorData['status_code'] ?? ''),
            errorMessage: $errorData['message'] ?? null
        );
    }

    /**
     * @deprecated Use specific forbid methods instead (forbidIpNotWhitelisted, forbidIpRateLimited, etc.)
     */
    public function forbid(): void
    {
        // Legacy fallback - treat as IP not whitelisted for backwards compatibility
        $this->createForbiddenRecord(
            type: ForbiddenHostname::TYPE_IP_NOT_WHITELISTED,
            accountId: $this->account->id,
            forbiddenUntil: now()->addHours(24), // Auto-retry after 24 hours
            errorCode: null,
            errorMessage: null
        );
    }

    public function retryException(Throwable $exception): bool
    {
        return $this->containsHttpExceptionIn($exception, $this->retryableHttpCodes);
    }

    public function ignoreException(Throwable $exception): bool
    {
        return $this->containsHttpExceptionIn($exception, $this->ignorableHttpCodes);
    }

    /**
     * --------------
     * Backoff helpers
     * --------------
     */

    /**
     * Generic "retry-at" computation:
     * • Prefer Retry-After (seconds or RFC-date).
     * • Otherwise fall back to a fixed base backoff with a tiny jitter.
     * Handlers may override to use window boundaries (e.g., minute/day/month).
     */
    public function rateLimitUntil(RequestException $exception): Carbon
    {
        $now = Carbon::now();

        if (! $exception->hasResponse()) {
            return $now->copy()->addSeconds($this->backoffSeconds);
        }

        $meta = $this->extractHttpMeta($exception);
        $retryAfter = mb_trim((string) ($meta['retry_after'] ?? ''));

        if ($retryAfter !== '') {
            if (is_numeric($retryAfter)) {
                return $now->copy()->addSeconds((int) $retryAfter + random_int(2, 6));
            }

            try {
                $parsed = Carbon::parse($retryAfter);

                return $parsed->isPast()
                    ? $now->copy()->addSeconds($this->backoffSeconds)
                    : $parsed;
            } catch (Throwable) {
                // fall through to base backoff
            }
        }

        // Base fallback + light jitter
        return $now->copy()->addSeconds($this->backoffSeconds + random_int(1, 5));
    }

    /**
     * Legacy wrapper returning seconds until retry for callers that expect an int.
     * Uses rateLimitUntil() when the exception is classified as rate-limited.
     */
    public function backoffSeconds(Throwable $e): int
    {
        if ($e instanceof RequestException && $e->hasResponse()) {
            if ($this->isRateLimited($e)) {
                $until = $this->rateLimitUntil($e);

                return max(0, now()->diffInSeconds($until, false));
            }
        }

        return $this->backoffSeconds;
    }

    /**
     * Minimal body decoder for HTTP code + vendor JSON code/message.
     * NOTE: Also supports CoinMarketCap style payloads:
     *   { "status": { "error_code": 1008, "error_message": "...", ... }, "data": ... }
     */
    public function extractHttpErrorCodes(Throwable|ResponseInterface $input): array
    {
        $httpCode = null;
        $statusCode = null;
        $message = null;
        $body = null;

        if ($input instanceof ResponseInterface) {
            $httpCode = $input->getStatusCode();
            $body = (string) $input->getBody();
        } elseif ($input instanceof RequestException && $input->hasResponse()) {
            $httpCode = $input->getResponse()->getStatusCode();
            $body = (string) $input->getResponse()->getBody();
        }

        if ($body) {
            $json = json_decode($body, associative: true);
            if (is_array($json)) {
                // Common schema (e.g., Binance-like)
                $statusCode = $json['code'] ?? null;
                $message = $json['msg'] ?? null;

                // CoinMarketCap schema (nested "status")
                if (($statusCode === null || $message === null) && isset($json['status']) && is_array($json['status'])) {
                    $statusCode = $json['status']['error_code'] ?? $statusCode;
                    $message = $json['status']['error_message'] ?? $message;
                }
            }
        }

        return [
            'http_code' => $httpCode,
            'status_code' => $statusCode,
            'message' => $message,
        ];
    }

    /**
     * Create or update a ForbiddenHostname record.
     * Sends notification only for new records.
     */
    protected function createForbiddenRecord(
        string $type,
        ?int $accountId,
        ?CarbonInterface $forbiddenUntil,
        ?string $errorCode,
        ?string $errorMessage
    ): void {
        $apiSystem = ApiSystem::canonical($this->getApiSystem())->firstOrFail();
        $ipAddress = Kraite::ip();

        $record = ForbiddenHostname::updateOrCreate(
            [
                'api_system_id' => $apiSystem->id,
                'account_id' => $accountId,
                'ip_address' => $ipAddress,
                'type' => $type,
            ],
            [
                'forbidden_until' => $forbiddenUntil,
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
                'updated_at' => now(),
            ]
        );

        // Notification is sent by ForbiddenHostnameObserver::created() when the record is newly created
    }

    /**
     * ----------------
     * Low-level helpers
     * ----------------
     */

    /**
     * Match a RequestException to a map of HTTP/vendor codes.
     * $statusCodes can be:
     *  • flat list: [429, 418]
     *  • map: [400 => [-1021, -5028], 401 => [-2015]]
     *
     * Walks the $exception->getPrevious() chain until it finds a
     * RequestException. Some atomic jobs wrap the raw Guzzle ClientException
     * in a RuntimeException to attach post-mortem diagnostics (intended vs
     * actual values after sync, etc.) — without this walk, the ignore /
     * retry classifier would lose sight of the underlying vendor code and
     * every wrapped API failure would end up in the generic fail path.
     */
    protected function containsHttpExceptionIn(Throwable $exception, array $statusCodes): bool
    {
        $requestException = $this->firstRequestExceptionIn($exception);

        if ($requestException === null) {
            return false;
        }

        $data = $this->extractHttpErrorCodes($requestException);
        $httpCode = $data['http_code'];
        $statusCode = $data['status_code'];

        if (array_key_exists(key: $httpCode, array: $statusCodes)) {
            $codes = $statusCodes[$httpCode];

            return is_array($codes) && $codes !== []
                ? in_array($statusCode, $codes, strict: true)
                : true;
        }

        return in_array($httpCode, $statusCodes, strict: true);
    }

    /**
     * Normalize headers (lower-case keys, comma-joined values).
     */
    protected function normalizeHeaders(MessageInterface $msg): array
    {
        $headers = [];
        foreach ($msg->getHeaders() as $k => $vals) {
            $headers[mb_strtolower($k)] = implode(separator: ', ', array: $vals);
        }

        return $headers;
    }

    /**
     * Extract normalized headers plus common fields (Retry-After, Date).
     * Venue-specific handlers can parse extra headers from $meta['headers'] if needed.
     */
    protected function extractHttpMeta(RequestException|ResponseInterface $input): array
    {
        $meta = $this->extractHttpErrorCodes($input);
        $headers = [];

        if ($input instanceof RequestException && $input->hasResponse()) {
            $headers = $this->normalizeHeaders($input->getResponse());
        } elseif ($input instanceof ResponseInterface) {
            $headers = $this->normalizeHeaders($input);
        }

        $meta['headers'] = $headers;
        $meta['retry_after'] = Arr::get($headers, 'retry-after');
        $meta['server_date'] = Arr::get($headers, 'date');

        return $meta;
    }

    /**
     * Compute the next reset boundary for a given (intervalNum, intervalLetter) from a server time.
     * Provided as a generic utility for handlers that know their windowing.
     */
    protected function nextWindowResetAt(Carbon $serverNow, int $intervalNum, string $intervalLetter): Carbon
    {
        $letter = mb_strtolower($intervalLetter);
        $t = $serverNow->copy();

        switch ($letter) {
            case 's':
                $sec = (int) $t->second;
                $next = (int) (floor($sec / $intervalNum) * $intervalNum + $intervalNum);

                return $t->copy()->second($next)->startOfSecond();

            case 'm':
                $min = (int) $t->minute;
                $next = (int) (floor($min / $intervalNum) * $intervalNum + $intervalNum);

                return $t->copy()->minute($next)->second(0)->startOfSecond();

            case 'h':
                $hr = (int) $t->hour;
                $next = (int) (floor($hr / $intervalNum) * $intervalNum + $intervalNum);

                return $t->copy()->hour($next)->minute(0)->second(0)->startOfSecond();

            case 'd':
                return $t->copy()->startOfDay()->addDays($intervalNum);

            default:
                return $t->copy()->startOfMinute()->addMinute();
        }
    }

    /**
     * Walk a Throwable and its $previous chain, returning the first
     * RequestException found or null. Depth-bounded to avoid pathological
     * loops in maliciously-constructed exceptions.
     */
    private function firstRequestExceptionIn(Throwable $exception): ?RequestException
    {
        $current = $exception;
        $depth = 0;

        while ($current !== null && $depth < 10) {
            if ($current instanceof RequestException) {
                return $current;
            }

            $current = $current->getPrevious();
            $depth++;
        }

        return null;
    }
}
