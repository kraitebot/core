<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\CoinmarketCap\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\Symbol;
use Kraite\Core\Support\ValueObjects\ApiProperties;

trait MapsSyncMarketData
{
    public function prepareSyncMarketDataProperties(Symbol $symbol): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $symbol);
        $properties->set('options.id', $symbol->cmc_id);
        $properties->set('loggable', $symbol);

        return $properties;
    }

    public function resolveSyncMarketDataResponse(Response $response): array
    {
        return json_decode((string) $response->getBody(), associative: true);
    }
}
