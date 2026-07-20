<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Bybit\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\Account;
use Kraite\Core\Support\Math;
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
        $body = json_decode((string) $response->getBody(), associative: true);

        // Bybit V5 response structure: { result: { list: [...] } }
        $positionsList = $body['result']['list'] ?? [];

        $positions = collect($positionsList)
            ->map(function ($position) {
                // Format symbol using exchange-specific convention (BTCUSDT for Bybit)
                if (isset($position['symbol'])) {
                    $parts = $this->identifyBaseAndQuote($position['symbol']);
                    $position['symbol'] = $this->baseWithQuote($parts['base'], $parts['quote']);
                }

                // Bybit uses 'side' with Buy/Sell values
                $side = mb_strtoupper($position['side'] ?? 'BOTH');
                // size/positionAmt remain float for downstream contract.
                // Full BCMath migration of the position-quantity surface
                // is a follow-up.
                $size = abs((float) ($position['size'] ?? 0));

                // Add Binance-compatible fields for apiClose() compatibility
                // positionSide: LONG or SHORT (uppercase)
                $position['positionSide'] = $side === 'BUY' ? 'LONG' : ($side === 'SELL' ? 'SHORT' : 'BOTH');
                // positionAmt: positive for long, negative for short (Binance convention)
                $position['positionAmt'] = $side === 'SELL' ? -$size : $size;

                return $position;
            })
            ->keyBy(static function ($position) {
                // Preserve exchange-side identity so hedge-mode responses map
                // correctly. Kraite still permits only one side per symbol.
                // Bybit uses 'side' with Buy/Sell values
                $side = mb_strtoupper($position['side'] ?? 'BOTH');
                $direction = $side === 'BUY' ? 'LONG' : ($side === 'SELL' ? 'SHORT' : 'BOTH');

                return $position['symbol'].':'.$direction;
            })
            ->toArray();

        // Remove positions with zero size (Bybit uses 'size' field)
        return array_filter($positions, callback: static function ($position) {
            return ! Math::equal((string) ($position['size'] ?? '0'), '0');
        });
    }
}
