<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ValueObjects;

final class ApiRequest
{
    public string $method;

    public string $path;

    public ?ApiProperties $properties;

    public function __construct(?string $method = null, ?string $path = null, ?ApiProperties $properties = null)
    {
        $this->method = $method;
        $this->path = $path;
        $this->properties = $properties ?? new ApiProperties;
    }

    public static function make(string $method, string $path, ?ApiProperties $properties = null): self
    {
        return new self($method, $path, $properties);
    }
}
