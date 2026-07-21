<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Health;

use Closure;
use Kraite\Core\Support\Health\Contracts\HealthCheck;

final readonly class CallbackHealthCheck implements HealthCheck
{
    /** @var Closure(): int */
    private Closure $callback;

    /** @param callable(): int $callback */
    public function __construct(private string $checkName, callable $callback)
    {
        $this->callback = Closure::fromCallable($callback);
    }

    public function name(): string
    {
        return $this->checkName;
    }

    public function run(): int
    {
        return ($this->callback)();
    }
}
