<?php

declare(strict_types=1);

namespace Kraite\Core\Abstracts;

use Exception;
use Kraite\Core\Concerns\BaseApiableJob\HandlesApiJobExceptions;
use Kraite\Core\Concerns\BaseApiableJob\HandlesApiJobLifecycle;
use Kraite\Core\Exceptions\NonNotifiableException;
use Kraite\Core\Models\ForbiddenHostname;
use Kraite\Core\Support\Proxies\ApiThrottlerProxy;
use Throwable;

abstract class BaseApiableJob extends BaseQueueableJob
{
    use HandlesApiJobExceptions;
    use HandlesApiJobLifecycle;

    public ?BaseExceptionHandler $exceptionHandler;

    abstract public function computeApiable();

    protected function compute()
    {
        if (! method_exists($this, 'assignExceptionHandler')) {
            throw new Exception('Exception handler not instanciated!');
        }

        $this->assignExceptionHandler();

        // Is this hostname forbidden for this API system and account?
        $apiSystemCanonical = $this->exceptionHandler->getApiSystem();
        $apiSystem = \Kraite\Core\Models\ApiSystem::where('canonical', $apiSystemCanonical)->firstOrFail();
        $accountId = $this->exceptionHandler->account->id;
        $ipAddress = \Kraite\Core\Models\Kraite::ip();

        // Check forbidden status based on account type:
        // - Admin accounts (transient, id = NULL): Check system-wide ban only
        // - User accounts (real, id != NULL): Check both account-specific AND system-wide bans
        //
        // Active bans are those where:
        // - forbidden_until is NULL (permanent/user-fixable) OR
        // - forbidden_until is in the future (temporary ban still active)
        $activeBanCondition = static function ($query) {
            $query->whereNull('forbidden_until')
                ->orWhere('forbidden_until', '>', now());
        };

        if ($accountId === null) {
            // Admin account - check system-wide ban only
            $isForbidden = ForbiddenHostname::query()
                ->where('api_system_id', $apiSystem->id)
                ->where('ip_address', $ipAddress)
                ->whereNull('account_id')
                ->where($activeBanCondition)
                ->exists();
        } else {
            // User account - check both account-specific AND system-wide bans
            $isForbidden = ForbiddenHostname::query()
                ->where('api_system_id', $apiSystem->id)
                ->where('ip_address', $ipAddress)
                ->where(static function ($query) use ($accountId) {
                    $query->where('account_id', $accountId)
                        ->orWhereNull('account_id');
                })
                ->where($activeBanCondition)
                ->exists();
        }

        if ($isForbidden) {
            // Place back the job in the queue;
            $this->retryJob();

            return;
        }

        try {
            return $this->computeApiable();
        } catch (Throwable $e) {
            // Let the API-specific exception handler deal with the error.
            $this->handleApiException($e);
        }
    }

    /**
     * Automatic API throttling.
     * Checks if the API system has a throttler and enforces rate limits.
     * Also performs pre-flight safety checks (IP bans, rate limit proximity).
     */
    protected function shouldStartOrThrottle(): bool
    {
        $stepId = $this->step->id;

        // Ensure exception handler is assigned before safety checks
        if (! isset($this->exceptionHandler)) {
            $this->assignExceptionHandler();
        }

        // 0. First check exception handler's pre-flight safety check
        if (isset($this->exceptionHandler) && ! $this->exceptionHandler->isSafeToMakeRequest()) {
            $this->jobBackoffSeconds = 5; // Default 5 second backoff when not safe

            return false; // Not safe - wait and retry
        }

        // Get throttler for this API system
        $throttler = $this->getThrottlerForApiSystem();

        if (! $throttler) {
            return true; // No throttler = proceed
        }

        // Extract account ID for per-account rate limit tracking (e.g., Binance ORDER limits)
        $accountId = $this->exceptionHandler->account?->id;

        // 1. First check IP-based safety (bans, rate limit proximity) if throttler supports it
        if (method_exists($throttler, 'isSafeToDispatch')) {
            $secondsToWait = $throttler::isSafeToDispatch($accountId, $stepId);

            if ($secondsToWait > 0) {
                $this->jobBackoffSeconds = $secondsToWait;

                return false; // Not safe - wait and retry
            }
        }

        // 2. Then check standard throttling (rate limits, min delays, etc.)
        $retryCount = $this->step->retries ?? 0;
        $secondsToWait = $throttler::canDispatch($retryCount, $accountId, $stepId);

        if ($secondsToWait > 0) {
            $this->jobBackoffSeconds = $secondsToWait;

            return false; // Throttled - retry
        }

        return true; // OK to proceed
    }

    /**
     * Get the throttler class for the current API system.
     * Returns null if no throttler exists (graceful degradation).
     */
    protected function getThrottlerForApiSystem(): ?string
    {
        if (! isset($this->exceptionHandler)) {
            $this->assignExceptionHandler();
        }

        $apiSystem = $this->exceptionHandler->getApiSystem();

        return ApiThrottlerProxy::getThrottler($apiSystem);
    }

    /**
     * Override shouldExitEarly to inject API throttling checks
     * into the lifecycle before standard BaseQueueableJob checks.
     */
    protected function shouldExitEarly(): bool
    {
        // Run standard lifecycle checks first
        if (! $this->shouldStartOrStop()) {
            $this->stopJob();

            return true;
        }

        if (! $this->shouldStartOrFail()) {
            throw new NonNotifiableException("startOrFail() returned false for Step ID {$this->step->id}");
        }

        if (! $this->shouldStartOrSkip()) {
            $this->skipJob();

            return true;
        }

        if (! $this->shouldStartOrRetry()) {
            $this->retryJob();

            return true;
        }

        // API throttle check - ensures rate limits are respected across all workers
        if (! $this->shouldStartOrThrottle()) {
            $this->rescheduleWithoutRetry();

            return true;
        }

        // Check max retries AFTER all lifecycle checks
        $this->checkMaxRetries();

        return false;
    }

    /**
     * Override to automatically record API dispatch before executing.
     */
    protected function executeJobLogic(): void
    {
        if ($this->step->double_check === 0) {
            // Automatically record API dispatch BEFORE calling computeApiable()
            $throttler = $this->getThrottlerForApiSystem();

            if ($throttler) {
                // Extract account ID for per-account rate limit tracking
                $accountId = $this->exceptionHandler->account?->id;
                $stepId = $this->step->id;
                $throttler::recordDispatch($accountId, $stepId);
            }

            // Now call the parent implementation
            parent::executeJobLogic();
        }
    }

    /**
     * Hook: Delegate retry decisions to the API exception handler.
     */
    protected function externalRetryException(Throwable $e): bool
    {
        return isset($this->exceptionHandler)
            && method_exists($this->exceptionHandler, 'retryException')
            && $this->exceptionHandler->retryException($e);
    }

    /**
     * Hook: Delegate ignore decisions to the API exception handler.
     */
    protected function externalIgnoreException(Throwable $e): bool
    {
        return isset($this->exceptionHandler)
            && method_exists($this->exceptionHandler, 'ignoreException')
            && $this->exceptionHandler->ignoreException($e);
    }

    /**
     * Hook: Delegate exception resolution to the API exception handler.
     */
    protected function externalResolveException(Throwable $e): void
    {
        if (isset($this->exceptionHandler)
            && method_exists($this->exceptionHandler, 'resolveException')
        ) {
            $this->exceptionHandler->resolveException($e);
        }
    }

    /**
     * Override to provide API-specific diagnostic information for retry failures.
     * Checks if the current hostname is forbidden for this account/API system.
     */
    protected function getRetryDiagnostics(): array
    {
        $diagnostics = [];

        try {
            if (! isset($this->exceptionHandler)) {
                $this->assignExceptionHandler();
            }

            $ipAddress = \Kraite\Core\Models\Kraite::ip();
            $forbiddenHostname = ForbiddenHostname::query()
                ->where('account_id', $this->exceptionHandler->account->id)
                ->where('ip_address', $ipAddress)
                ->first();

            if ($forbiddenHostname) {
                $apiSystemName = $this->exceptionHandler->account->apiSystem->name ?? 'exchange';
                $diagnostics[] = "Server IP {$ipAddress} is not whitelisted on {$apiSystemName}. Please add this IP to your API key whitelist.";
            }
        } catch (Throwable $e) {
            // Silently skip diagnostics if anything fails
        }

        return $diagnostics;
    }
}
