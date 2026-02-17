<?php

declare(strict_types=1);

namespace Kraite\Core\Concerns\BaseApiableJob;

use Exception;

/*
 * HandlesApiJobLifecycle
 *
 * • Provides essential lifecycle checks for API-driven jobs.
 * • Ensures required dependencies like rateLimiter and exceptionHandler
 *   are instantiated before job execution.
 * • Validates whether polling is currently limited via rate limits
 *   or forbidden flags from the rate limiter.
 */
trait HandlesApiJobLifecycle
{
    protected function checkApiRequiredClasses(): void
    {
        /*
         * Ensure the job has a RateLimiter instance defined.
         */
        if (! isset($this->rateLimiter)) {
            throw new Exception('Rate Limiter class not instanciated on '.static::class);
        }

        /*
         * Ensure the job has an ExceptionHandler instance defined.
         */
        if (! isset($this->exceptionHandler)) {
            throw new Exception('Exception Handler class not instanciated on '.static::class);
        }
    }

    protected function isPollingLimited(): bool
    {
        /*
         * Return true if this account is currently rate limited
         * or polling has been indefinitely forbidden.
         */
        return $this->rateLimiter->isRateLimited() || $this->rateLimiter->isForbidden();
    }
}
