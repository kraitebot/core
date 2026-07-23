<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Health;

use Kraite\Core\Support\Health\Contracts\HealthCheck;
use Kraite\Core\Support\Health\Contracts\SystemHealthProbe;

final readonly class SystemHealthCheck implements HealthCheck
{
    public function __construct(
        private SystemHealthCheckType $type,
        private SystemHealthProbe $probe,
    ) {}

    public function name(): string
    {
        return $this->type->value;
    }

    public function run(): int
    {
        return $this->type->run($this->probe);
    }
}
