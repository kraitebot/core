<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Binance\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\Account;
use Kraite\Core\Support\ValueObjects\ApiProperties;

trait MapsPositionsQuery
{
    public function prepareQueryPositionsProperties(Account $account): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $account);

        return $properties;
    }

    public function resolveQueryPositionsResponse(Response $response): array
    {
        $positions = collect(json_decode((string) $response->getBody(), associative: true))
            ->map(function ($position) {
                // Format symbol using exchange-specific convention (BTCUSDT for Binance)
                if (isset($position['symbol'])) {
                    $parts = $this->identifyBaseAndQuote($position['symbol']);
                    $position['symbol'] = $this->baseWithQuote($parts['base'], $parts['quote']);
                }

                return $position;
            })
            ->keyBy(static function ($position) {
                // Key by symbol:direction to support hedge mode (LONG + SHORT on same symbol)
                $direction = $position['positionSide'] ?? 'BOTH';

                return $position['symbol'].':'.$direction;
            })
            ->toArray();

        // Remove false positive positions (positionAmt = 0.0)
        return array_filter($positions, callback: static function ($position) {
            return (float) $position['positionAmt'] !== 0.0;
        });
    }
}
