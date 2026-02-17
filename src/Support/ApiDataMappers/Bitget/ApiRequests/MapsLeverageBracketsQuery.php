<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Bitget\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Support\ValueObjects\ApiProperties;

trait MapsLeverageBracketsQuery
{
    /**
     * Prepare properties for querying position tiers on BitGet Futures.
     *
     * BitGet returns all symbols when no symbol specified, but can filter by symbol.
     *
     * @see https://www.bitget.com/api-doc/contract/position/Get-Query-Position-Lever
     */
    public function prepareQueryLeverageBracketsDataProperties(ApiSystem $apiSystem, ?string $symbol = null): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $apiSystem);
        $properties->set('options.productType', 'USDT-FUTURES');

        // BitGet can filter by symbol if provided
        if ($symbol) {
            $properties->set('options.symbol', $symbol);
        }

        return $properties;
    }

    /**
     * Resolve position tier response from BitGet.
     *
     * BitGet V2 response structure:
     * {
     *     "code": "00000",
     *     "data": [
     *         {
     *             "symbol": "BTCUSDT",
     *             "level": "1",
     *             "startUnit": "0",
     *             "endUnit": "50000",
     *             "leverage": "125",
     *             "keepMarginRate": "0.004"
     *         }
     *     ]
     * }
     */
    public function resolveLeverageBracketsDataResponse(Response $response): array
    {
        $body = json_decode((string) $response->getBody(), associative: true);
        $tiers = $body['data'] ?? [];

        // Group by symbol and normalize to match other exchanges
        $grouped = [];
        foreach ($tiers as $tier) {
            $symbol = $tier['symbol'] ?? '';
            if ($symbol === '') {
                continue;
            }

            if (! isset($grouped[$symbol])) {
                $grouped[$symbol] = [
                    'symbol' => $symbol,
                    'brackets' => [],
                ];
            }

            $grouped[$symbol]['brackets'][] = [
                'bracket' => (int) ($tier['level'] ?? 0),
                'initialLeverage' => (int) ($tier['leverage'] ?? 0),
                'notionalCap' => (float) ($tier['endUnit'] ?? 0),
                'notionalFloor' => (float) ($tier['startUnit'] ?? 0),
                'maintMarginRatio' => (float) ($tier['keepMarginRate'] ?? 0),
            ];
        }

        return array_values($grouped);
    }
}
