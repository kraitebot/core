<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Binance\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\ValueObjects\ApiProperties;

trait MapsSymbolMarginType
{
    /**
     * Prepare properties for updating margin type on Binance.
     *
     * Binance expects: ISOLATED or CROSSED (uppercase).
     */
    public function prepareUpdateMarginTypeProperties(Position $position): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $position);

        $properties->set('options.symbol', $position->exchangeSymbol->parsed_trading_pair);

        // Get margin mode from account (isolated/crossed) and convert to Binance format (ISOLATED/CROSSED)
        $marginMode = mb_strtoupper($position->account->margin_mode);
        $properties->set('options.margintype', $marginMode);

        return $properties;
    }

    public function resolveUpdateMarginTypeResponse(Response $response): array
    {
        return json_decode((string) $response->getBody(), associative: true);
    }
}
