<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Binance\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\ValueObjects\ApiProperties;

trait MapsAccountQueryTrades
{
    public function prepareQueryTokenTradesProperties(Position $position, ?string $orderId = null): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $position);

        if (! is_null($orderId)) {
            $properties->set('options.orderId', (string) $orderId);
        }

        $properties->set('options.symbol', (string) $position->exchangeSymbol->parsed_trading_pair);

        // Binance defaults to returning up to 500 trades. All we need is the
        // last reducing fill — a small cap drops payload size ~99% with no
        // behavioural change. `extractClosingPriceFromTrades` scans
        // newest-first and short-circuits on the first match.
        $properties->set('options.limit', '5');

        return $properties;
    }

    public function resolveQueryTradeResponse(Response $response): array
    {
        return json_decode((string) $response->getBody(), associative: true);
    }
}
