<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Health;

use Throwable;

final readonly class HealthCheckResult
{
    public function __construct(
        public string $name,
        public int $alertCount,
        public ?Throwable $exception = null,
    ) {}

    public function failed(): bool
    {
        return $this->exception !== null;
    }
}
