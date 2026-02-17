<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ValueObjects;

final class ApiCredentials
{
    public ?array $credentials;

    public function __construct(?array $credentials = [])
    {
        $this->credentials = $credentials;
    }

    public static function make(array $credentials = []): self
    {
        return new self($credentials);
    }

    public function set(string $key, mixed $value): self
    {
        data_set($this->credentials, $key, $value);

        return $this;
    }

    public function toArray(): array
    {
        return $this->credentials;
    }

    public function getOr(string $key, mixed $default = null): mixed
    {
        return data_get($this->credentials, $key, $default);
    }

    public function get(string $key)
    {
        return data_get($this->credentials, $key);
    }
}
