<?php

declare(strict_types=1);

namespace Kraite\Core\Trading\Exchange;

use Kraite\Core\Support\Proxies\ApiDataMapperProxy;
use Kraite\Core\Support\Proxies\ApiRESTProxy;
use Kraite\Core\Support\ValueObjects\ApiCredentials;

final readonly class CanonicalExchangeConnection
{
    public function __construct(private string $canonical) {}

    public function mapper(): ApiDataMapperProxy
    {
        return new ApiDataMapperProxy($this->canonical);
    }

    public function client(ApiCredentials $credentials): ApiRESTProxy
    {
        return new ApiRESTProxy($this->canonical, $credentials);
    }
}
