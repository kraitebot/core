<?php

declare(strict_types=1);

namespace Kraite\Core\Concerns\BaseApiableJob;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Artisan;

use Throwable;

/*
 * HandlesApiJobExceptions
 *
 * • Trait for BaseApiableJob classes to handle API-specific exceptions.
 * • Detects throttling, connectivity, and known RecvWindow issues.
 * • Automatically retries failed jobs by deferring dispatch with backoff.
 * • Triggers custom command to adjust recvWindow timing when applicable.
 * • Sets step state to Pending and flags it as updated after deferral.
 */
trait HandlesApiJobExceptions
{
    protected function handleApiException(Throwable $e): void
    {
        // Notifications are sent by ApiRequestLogObserver after log is persisted

        // Handle connection failures like timeouts or DNS issues.
        if ($e instanceof ConnectException) {
            $this->retryDueToNetworkGlitch();

            return;
        }

        if ($e instanceof RequestException) {
            if ($this->exceptionHandler->ignoreException($e)) {
                // Ignorable exceptions (like 400 Bad Request for invalid symbols)
                // Job completes normally, allowing computeApiable() to return its result
                return;
            }

            if ($this->exceptionHandler->isRecvWindowMismatch($e)) {
                $this->handleRecvWindowIssue($e);

                return;
            }

            // Case 1: IP not whitelisted by user (user-fixable)
            if ($this->exceptionHandler->isIpNotWhitelisted($e)) {
                $this->exceptionHandler->forbidIpNotWhitelisted($e);
                $this->retryJob(); // Put job back in queue for another worker

                return;
            }

            // Case 2: IP temporarily rate-limited (auto-recovers)
            // Check BEFORE generic isRateLimited() to ensure ForbiddenHostname record is created
            if ($this->exceptionHandler->isIpRateLimited($e)) {
                $this->exceptionHandler->forbidIpRateLimited($e);
                $this->retryJob(); // Put job back in queue for when ban expires

                return;
            }

            // Generic rate limiting (not IP-specific)
            if ($this->exceptionHandler->isRateLimited($e)) {
                $this->retryPerApiThrottlingDelay($e);

                return;
            }

            // Case 3: IP permanently banned for ALL accounts
            if ($this->exceptionHandler->isIpBanned($e)) {
                $this->exceptionHandler->forbidIpBanned($e);
                $this->retryJob(); // Put job back in queue for another worker/server

                return;
            }

            // Case 4: Account blocked (API key issue)
            if ($this->exceptionHandler->isAccountBlocked($e)) {
                $this->exceptionHandler->forbidAccountBlocked($e);
                $this->retryJob(); // Put job back in queue (won't help until user fixes key)

                return;
            }
        }

        // Re-throw if it's not handled by any of the above.
        throw $e;
    }

    protected function handleRecvWindowIssue($e): void
    {
        // Lets improve the recvwindow safety for a higher duration.
        try {
            Artisan::call('kraite:update-recvwindow-safety-duration');
        } catch (Throwable $commandException) {
            // Command might fail in test environment or when API is unavailable
            // We'll still retry with existing recvwindow_margin
        }

        $this->retryPerApiThrottlingDelay($e);
    }

    protected function retryPerApiThrottlingDelay(Throwable $e): void
    {
        /*
         * Set a future dispatch_after time and mark the step as pending.
         * This defers job execution based on rateLimiter's exchange policy.
         * Uses rescheduleWithoutRetry() because rate limits are not failures.
         */
        $retryAt = $this->exceptionHandler->rateLimitUntil($e);

        // Record IP ban in throttler for coordination across workers when applicable
        if ($e instanceof RequestException && $e->hasResponse()) {
            $statusCode = $e->getResponse()->getStatusCode();

            // Check if this is an IP ban scenario (418/429 for Binance, 403 for Bybit)
            if (in_array($statusCode, [418, 429, 403], strict: true)) {
                $retryAfterSeconds = (int) max(0, now()->diffInSeconds($retryAt, false));

                if ($retryAfterSeconds > 0) {
                    // Record ban in the appropriate throttler
                    $throttler = $this->getThrottlerForApiSystem();
                    if ($throttler && method_exists($throttler, 'recordIpBan')) {
                        $throttler::recordIpBan($retryAfterSeconds);
                    }
                }
            }
        }

        // Use rescheduleWithoutRetry() instead of retryJob()
        // Rate limits and recvWindow issues are throttling conditions, not failures
        // This prevents retry counter increment and eventual max retries exhaustion
        $this->rescheduleWithoutRetry($retryAt);
    }

    protected function retryDueToNetworkGlitch(): void
    {
        // Just apply a standard rate limiter retry.
        $this->retryJob(now()->addSeconds($this->exceptionHandler->backoffSeconds));
    }
}
