<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Kucoin\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Support\ValueObjects\ApiProperties;

trait MapsLeverageBracketsQuery
{
    /**
     * Prepare properties for querying risk limit levels on KuCoin Futures.
     *
     * KuCoin requires symbol in the URL path: /api/v1/contracts/risk-limit/{symbol}
     *
     * @see https://www.kucoin.com/docs/rest/futures-trading/risk-limit/get-futures-risk-limit-level
     */
    public function prepareQueryLeverageBracketsDataProperties(ApiSystem $apiSystem, ?string $symbol = null): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $apiSystem);

        // KuCoin requires symbol parameter for risk limit endpoint
        if ($symbol) {
            $properties->set('options.symbol', $symbol);
        }

        return $properties;
    }

    /**
     * Resolve the risk limit levels response from KuCoin.
     *
     * KuCoin response structure:
     * {
     *     "code": "200000",
     *     "data": [
     *         {
     *             "symbol": "XBTUSDTM",
     *             "level": 1,
     *             "maxRiskLimit": 200000,
     *             "minRiskLimit": 0,
     *             "maxLeverage": 100,
     *             "initialMargin": 0.01,
     *             "maintainMargin": 0.005
     *         },
     *         {
     *             "symbol": "XBTUSDTM",
     *             "level": 2,
     *             "maxRiskLimit": 500000,
     *             "minRiskLimit": 200000,
     *             "maxLeverage": 50,
     *             "initialMargin": 0.02,
     *             "maintainMargin": 0.01
     *         }
     *     ]
     * }
     */
    public function resolveLeverageBracketsDataResponse(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), associative: true);
        $riskLimits = $data['data'] ?? [];

        // Group by symbol for easier lookup
        $grouped = [];
        foreach ($riskLimits as $limit) {
            $symbol = $limit['symbol'] ?? '';
            if ($symbol === '') {
                continue;
            }

            if (! isset($grouped[$symbol])) {
                $grouped[$symbol] = [
                    'symbol' => $symbol,
                    'maxLeverage' => $limit['maxLeverage'] ?? 0,
                    'levels' => [],
                ];
            }

            $grouped[$symbol]['levels'][] = [
                'level' => $limit['level'] ?? 0,
                'maxRiskLimit' => $limit['maxRiskLimit'] ?? 0,
                'minRiskLimit' => $limit['minRiskLimit'] ?? 0,
                'maxLeverage' => $limit['maxLeverage'] ?? 0,
                'initialMargin' => $limit['initialMargin'] ?? 0,
                'maintainMargin' => $limit['maintainMargin'] ?? 0,
            ];
        }

        return $grouped;
    }
}
