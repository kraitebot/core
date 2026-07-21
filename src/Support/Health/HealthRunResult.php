<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Health;

final readonly class HealthRunResult
{
    /**
     * @param  list<HealthCheckResult>  $results
     */
    public function __construct(private array $results) {}

    public function alertCount(): int
    {
        return array_sum(array_map(
            static fn (HealthCheckResult $result): int => $result->alertCount,
            $this->results,
        ));
    }

    public function failed(): bool
    {
        return $this->failures() !== [];
    }

    /**
     * @return list<HealthCheckResult>
     */
    public function failures(): array
    {
        return array_values(array_filter(
            $this->results,
            static fn (HealthCheckResult $result): bool => $result->failed(),
        ));
    }

    /**
     * @return list<HealthCheckResult>
     */
    public function results(): array
    {
        return $this->results;
    }
}
