<?php

declare(strict_types=1);

namespace Kraite\Core\Observers;

use DB;
use Exception;
use Illuminate\Support\Facades\Log;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Models\ApiRequestLog;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Support\NotificationHandlers\BaseNotificationHandler;
use Kraite\Core\Support\NotificationService;

final class ApiRequestLogObserver
{
    /**
     * Handle the ApiRequestLog "saved" event.
     *
     * Three independent responsibilities run synchronously here. Each is
     * wrapped in its own try/catch so a transient DB blip or unexpected
     * shape in branch N cannot prevent branches N+1..M from running, AND
     * cannot cascade out of the observer back into the model save (which
     * Eloquent would re-raise into the calling job, misclassifying the
     * failure). Failures are surfaced via Log::error so they remain
     * visible in ops, but never block the save event itself.
     *
     * Mirrors the failure-containment shape used in
     * NotificationService::sendToSpecificUser() and BaseQueueableJob::onExceptionLogged().
     */
    public function saved(ApiRequestLog $log): void
    {
        // Send notification if needed
        try {
            $this->sendNotificationIfNeeded($log);
        } catch (\Throwable $e) {
            Log::error('[ApiRequestLogObserver] sendNotificationIfNeeded failed; swallowed to protect sibling branches and the calling job', [
                'api_request_log_id' => $log->id ?? null,
                'api_system_id' => $log->api_system_id ?? null,
                'http_response_code' => $log->http_response_code ?? null,
                'exception_class' => $e::class,
                'exception_message' => $e->getMessage(),
            ]);
        }

        // Trigger exchange cooldown on server instability
        try {
            $this->triggerExchangeCooldownIfNeeded($log);
        } catch (\Throwable $e) {
            Log::error('[ApiRequestLogObserver] triggerExchangeCooldownIfNeeded failed; swallowed to protect sibling branches and the calling job', [
                'api_request_log_id' => $log->id ?? null,
                'api_system_id' => $log->api_system_id ?? null,
                'http_response_code' => $log->http_response_code ?? null,
                'exception_class' => $e::class,
                'exception_message' => $e->getMessage(),
            ]);
        }

        // Auto-deactivate exchange symbols with no TAAPI data
        try {
            $this->deactivateExchangeSymbolIfNoTaapiData($log);
        } catch (\Throwable $e) {
            Log::error('[ApiRequestLogObserver] deactivateExchangeSymbolIfNoTaapiData failed; swallowed to protect sibling branches and the calling job', [
                'api_request_log_id' => $log->id ?? null,
                'api_system_id' => $log->api_system_id ?? null,
                'http_response_code' => $log->http_response_code ?? null,
                'exception_class' => $e::class,
                'exception_message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send notification for API errors.
     * No business logic - just notification routing based on error type.
     */
    private function sendNotificationIfNeeded(ApiRequestLog $log): void
    {
        // Skip if no HTTP response code yet (request still in progress)
        if ($log->http_response_code === null) {
            return;
        }

        // Load API system to determine which notification handler to use
        $apiSystem = ApiSystem::find($log->api_system_id);
        if (! $apiSystem) {
            return;
        }

        // Create the appropriate notification handler for error code analysis
        try {
            $handler = BaseNotificationHandler::make($apiSystem->canonical);
        } catch (Exception $e) {
            // No notification handler for this API system (e.g., taapi, coinmarketcap)
            return;
        }

        $httpCode = $log->http_response_code;
        $vendorCode = $handler->extractVendorCode($log->response);

        // Skip if truly successful (HTTP success AND no vendor error)
        // Some APIs (Bybit) return HTTP 200 with error codes in the response body
        if ($httpCode < 400 && $vendorCode === null) {
            return;
        }

        // Get the notification canonical for this error
        $canonical = $handler->getCanonical($httpCode, $vendorCode);
        if ($canonical === null) {
            return;
        }

        // Load account with user for context
        $account = $log->account()->with('user')->first();
        $hostname = $log->hostname ?? gethostname();

        // Build reference data using Eloquent model names
        $referenceData = [
            'apiSystem' => $apiSystem,
            'apiRequestLog' => $log,
            'account' => $account,
            'user' => $account?->user,
            'server' => $hostname,
        ];

        // Build cache keys based on canonical
        $cacheKeys = match ($canonical) {
            'server_rate_limit_exceeded' => [
                'api_system' => $apiSystem->canonical,
                'account' => $log->account_id ?? 0,
                'server' => $hostname,
            ],
            'server_ip_forbidden' => [
                'api_system' => $apiSystem->canonical,
                'server' => $hostname,
            ],
            default => [],
        };

        NotificationService::send(
            user: Kraite::admin(),
            canonical: $canonical,
            referenceData: $referenceData,
            relatable: $apiSystem,
            cacheKeys: $cacheKeys
        );
    }

    /**
     * Trigger exchange cooldown when server instability is detected.
     * Only applies to exchange API systems (not data providers like TAAPI).
     */
    private function triggerExchangeCooldownIfNeeded(ApiRequestLog $log): void
    {
        // Skip if no HTTP response code yet (request still in progress)
        if ($log->http_response_code === null) {
            return;
        }

        $apiSystem = ApiSystem::find($log->api_system_id);
        if (! $apiSystem || ! $apiSystem->is_exchange) {
            return;
        }

        try {
            $handler = BaseExceptionHandler::make($apiSystem->canonical);
        } catch (Exception $e) {
            return;
        }

        $httpCode = $log->http_response_code;
        $vendorCode = $handler->extractVendorCodeFromResponse($log->response);

        if ($handler->shouldTriggerCooldown($httpCode, $vendorCode)) {
            $apiSystem->activateCooldown();
        }
    }

    private function deactivateExchangeSymbolIfNoTaapiData(ApiRequestLog $log): void
    {
        // Only process TAAPI requests
        $taapiSystem = ApiSystem::where('canonical', 'taapi')->first();
        if (! $taapiSystem || $log->api_system_id !== $taapiSystem->id) {
            return;
        }

        // Only process permanent "no data" errors
        if (! $this->isPermanentNoDataError($log)) {
            return;
        }

        // Parse payload to identify the ExchangeSymbol
        $exchangeSymbol = $this->resolveExchangeSymbolFromPayload($log);
        if (! $exchangeSymbol) {
            return;
        }

        // Atomic state transition under pessimistic lock — only the winning
        // worker flips the flags. Concurrent workers see `taapi_verified=false`
        // after the lock releases and return without re-firing.
        //
        // Notification dedupe is independent: NotificationService keys on
        // (exchange_symbol, exchange) via Cache::add() (atomic SETNX), so
        // even if two workers somehow reached `sendDeactivationNotification`
        // they would collapse to a single emission. That makes it safe — and
        // strongly preferable — to send AFTER commit instead of inside the
        // transaction. Mail/Pushover/Telegram channels have multi-second
        // latency; holding `lockForUpdate()` across an HTTP roundtrip starves
        // sibling workers that need the same row.
        $deactivatedSymbol = DB::transaction(function () use ($exchangeSymbol, $log) {
            $lockedSymbol = ExchangeSymbol::where('id', $exchangeSymbol->id)
                ->lockForUpdate()
                ->first();

            // Skip if already stopped receiving indicator data (checked inside transaction after lock acquired)
            $taapiVerified = $lockedSymbol->api_statuses['taapi_verified'] ?? false;
            if (! $lockedSymbol || ! $taapiVerified) {
                return null;
            }

            // Check if this is a consistent pattern (last N requests all failed)
            if (! $this->hasConsistentFailurePattern($lockedSymbol, $log)) {
                return null;
            }

            // Deactivate the ExchangeSymbol and stop receiving indicator data
            $apiStatuses = $lockedSymbol->api_statuses ?? [];
            $apiStatuses['taapi_verified'] = false;
            $apiStatuses['has_taapi_data'] = false;
            $lockedSymbol->update([
                'has_no_indicator_data' => true,
                'api_statuses' => $apiStatuses,
            ]);

            return $lockedSymbol->fresh();
        });

        // Notify after commit so channel latency does not hold the row lock.
        if ($deactivatedSymbol !== null) {
            $this->sendDeactivationNotification($deactivatedSymbol, $log);
        }
    }

    private function isPermanentNoDataError(ApiRequestLog $log): bool
    {
        // Must be 400 error
        if ($log->http_response_code !== 400) {
            return false;
        }

        $response = is_string($log->response) ? $log->response : json_encode($log->response);

        // Check for known "no data" patterns
        $noDataPatterns = [
            'No candles were found!',
            'An unknown error occurred. Please check your parameters',
            'No data available',
            'Symbol not found',
        ];

        foreach ($noDataPatterns as $pattern) {
            if (str_contains(haystack: $response, needle: $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function resolveExchangeSymbolFromPayload(ApiRequestLog $log): ?ExchangeSymbol
    {
        $payload = is_string($log->payload) ? json_decode($log->payload, associative: true) : $log->payload;

        if (! isset($payload['options']['exchange'], $payload['options']['symbol'])) {
            return null;
        }

        $exchange = $payload['options']['exchange']; // "bybit" or "binancefutures"
        $symbolWithQuote = $payload['options']['symbol']; // "1000BONK/USDT" or "ETC/USDC"

        // Split symbol and quote
        $parts = explode('/', $symbolWithQuote);
        if (count($parts) !== 2) {
            return null;
        }

        [$baseToken, $quoteCanonical] = $parts;

        // Map TAAPI exchange name to ApiSystem
        $exchangeCanonical = match ($exchange) {
            'binancefutures' => 'binance',
            'bybit' => 'bybit',
            default => null,
        };

        if (! $exchangeCanonical) {
            return null;
        }

        $apiSystem = ApiSystem::where('canonical', $exchangeCanonical)->first();
        if (! $apiSystem) {
            return null;
        }

        // Find the ExchangeSymbol using token and quote columns directly
        return ExchangeSymbol::where('api_system_id', $apiSystem->id)
            ->where('token', $baseToken)
            ->where('quote', $quoteCanonical)
            ->first();
    }

    private function hasConsistentFailurePattern(ExchangeSymbol $exchangeSymbol, ApiRequestLog $currentLog): bool
    {
        // Build the search pattern for this specific exchange/symbol/quote combination
        $payload = is_string($currentLog->payload) ? json_decode($currentLog->payload, associative: true) : $currentLog->payload;
        $exchange = $payload['options']['exchange'] ?? null;
        $symbol = $payload['options']['symbol'] ?? null;

        if (! $exchange || ! $symbol) {
            return false;
        }

        $taapiSystem = ApiSystem::where('canonical', 'taapi')->first();

        // Escape the symbol for LIKE query (JSON stores it as "ETC\/USDC")
        // MySQL LIKE needs quadruple backslash in PHP to match JSON's escaped slash
        $escapedSymbol = str_replace(search: '/', replace: '\\\/', subject: $symbol);

        // Get last 5 requests for this same exchange/symbol/quote combination
        $recentLogs = ApiRequestLog::where('api_system_id', $taapiSystem->id)
            ->where('created_at', '>=', now()->subHours(24))
            ->where('payload', 'LIKE', "%{$exchange}%")
            ->where('payload', 'LIKE', "%{$escapedSymbol}%")
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();

        // Need at least 3 failed requests to confirm pattern
        if ($recentLogs->count() < 3) {
            return false;
        }

        // Check if ALL recent requests failed with "no data" error
        return $recentLogs->every(function ($log) {
            return $this->isPermanentNoDataError($log);
        });
    }

    private function sendDeactivationNotification(ExchangeSymbol $exchangeSymbol, ApiRequestLog $log): void
    {
        // Count how many failures occurred
        $payload = is_string($log->payload) ? json_decode($log->payload, associative: true) : $log->payload;
        $exchange = $payload['options']['exchange'] ?? null;
        $symbol = $payload['options']['symbol'] ?? null;
        $escapedSymbol = str_replace(search: '/', replace: '\\\/', subject: $symbol);

        $taapiSystem = ApiSystem::where('canonical', 'taapi')->first();
        $failureCount = ApiRequestLog::where('api_system_id', $taapiSystem->id)
            ->where('created_at', '>=', now()->subHours(24))
            ->where('payload', 'LIKE', "%{$exchange}%")
            ->where('payload', 'LIKE', "%{$escapedSymbol}%")
            ->where('http_response_code', 400)
            ->count();

        NotificationService::send(
            user: Kraite::admin(),
            canonical: 'exchange_symbol_no_taapi_data',
            referenceData: [
                'exchangeSymbol' => $exchangeSymbol,
                'failure_count' => $failureCount,
            ],
            relatable: $exchangeSymbol,
            cacheKeys: [
                'exchange_symbol' => $exchangeSymbol->id,
                'exchange' => $exchangeSymbol->apiSystem->canonical,
            ]
        );
    }
}
