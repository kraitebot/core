<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\CoinmarketCap\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Support\ValueObjects\ApiProperties;

/**
 * Batched /v1/cryptocurrency/map lookup keyed by CMC ids. Returns each
 * coin's CURRENT ticker and name, which is how the catalogue label
 * reconciliation notices rebrands (TON → GRAM) for coins that were
 * linked long before the rename.
 */
trait MapsSymbolMapByIds
{
    /**
     * @param  list<int>  $cmcIds
     */
    public function prepareSymbolMapByIdsProperties(array $cmcIds): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('options.id', implode(',', $cmcIds));

        return $properties;
    }

    /**
     * @return array<string, mixed>
     */
    public function resolveSymbolMapByIdsResponse(Response $response): array
    {
        return json_decode((string) $response->getBody(), associative: true);
    }
}
