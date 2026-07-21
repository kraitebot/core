<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Health\Contracts;

interface HealthCheck
{
    public function name(): string;

    public function run(): int;
}
