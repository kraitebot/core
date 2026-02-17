<?php

declare(strict_types=1);

namespace Kraite\Core\Concerns\ExchangeSymbol;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Support\Proxies\ApiDataMapperProxy;
use Kraite\Core\Support\ValueObjects\ApiProperties;

trait InteractsWithApis
{
    public ApiProperties $apiProperties;

    public Response $apiResponse;

    public function apiMapper($canonical)
    {
        return new ApiDataMapperProxy($canonical);
    }
}
