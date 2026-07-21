<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Health;

use Closure;
use Kraite\Core\Support\Health\Contracts\HealthCheck;
use Throwable;

final class HealthCheckRunner
{
    /**
     * @param  iterable<HealthCheck>  $checks
     * @param  (Closure(string, Throwable): int)|null  $onFailure
     */
    public function run(
        iterable $checks,
        ?Closure $onFailure = null,
        bool $continueAfterFailure = true,
    ): HealthRunResult {
        $results = [];

        foreach ($checks as $check) {
            try {
                $results[] = new HealthCheckResult(
                    name: $check->name(),
                    alertCount: $check->run(),
                );
            } catch (Throwable $exception) {
                if (! $continueAfterFailure) {
                    throw $exception;
                }

                $results[] = new HealthCheckResult(
                    name: $check->name(),
                    alertCount: $onFailure === null ? 0 : $onFailure($check->name(), $exception),
                    exception: $exception,
                );
            }
        }

        return new HealthRunResult($results);
    }
}
