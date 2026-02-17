<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Binance\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Support\ValueObjects\ApiProperties;

trait MapsLeverageBracketsQuery
{
    /**
     * Prepare properties for querying leverage brackets on Binance.
     *
     * Binance returns all symbols in one call (symbol parameter ignored).
     */
    public function prepareQueryLeverageBracketsDataProperties(ApiSystem $apiSystem, ?string $symbol = null): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $apiSystem);

        // Binance batch API - symbol parameter is ignored (returns all symbols)

        return $properties;
    }

    public function resolveLeverageBracketsDataResponse(Response $response): array
    {
        return json_decode((string) $response->getBody(), associative: true);
    }
}
