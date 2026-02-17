<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\CoinmarketCap\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Support\ValueObjects\ApiProperties;

trait MapsSearchSymbolByToken
{
    public function prepareSearchSymbolByTokenProperties(#[\SensitiveParameter] string $token): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('options.symbol', mb_strtoupper($token));
        $properties->set('options.limit', 10);

        return $properties;
    }

    public function resolveSearchSymbolByTokenResponse(Response $response): array
    {
        return json_decode((string) $response->getBody(), associative: true);
    }
}
