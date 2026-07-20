<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Kucoin\ApiRequests;

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

    /**
     * Resolves KuCoin open positions response.
     *
     * KuCoin Futures response structure:
     * {
     *     "code": "200000",
     *     "data": [
     *         {
     *             "id": "5ce3cda60c19fc0d4e9ae7cd",
     *             "symbol": "XBTUSDTM",
     *             "autoDeposit": true,
     *             "maintMarginReq": 0.005,
     *             "riskLimit": 2000000,
     *             "realLeverage": 1.06,
     *             "crossMode": false,
     *             "delevPercentage": 0.1,
     *             "openingTimestamp": 1558433191000,
     *             "currentTimestamp": 1558507727807,
     *             "currentQty": 20,
     *             "currentCost": 0.00266375,
     *             "currentComm": 0.00000271,
     *             "unrealisedCost": 0.00266375,
     *             "realisedGrossCost": 0,
     *             "realisedCost": 0.00000271,
     *             "isOpen": true,
     *             "markPrice": 7933.01,
     *             "markValue": 0.00252111,
     *             "posCost": 0.00266375,
     *             "posCross": 1.2e-7,
     *             "posInit": 0.00266375,
     *             "posComm": 0.00000392,
     *             "posLoss": 0,
     *             "posMargin": 0.00266779,
     *             "posMaint": 0.00001724,
     *             "maintMargin": 0.00252516,
     *             "realisedGrossPnl": 0,
     *             "realisedPnl": -0.00000271,
     *             "unrealisedPnl": -0.00014264,
     *             "unrealisedPnlPcnt": -0.0535,
     *             "unrealisedRoePcnt": -0.0535,
     *             "avgEntryPrice": 7508.22,
     *             "liquidationPrice": 1000000,
     *             "bankruptPrice": 1000000,
     *             "settleCurrency": "USDT"
     *         }
     *     ]
     * }
     */
    public function resolveQueryPositionsResponse(Response $response): array
    {
        $body = json_decode((string) $response->getBody(), associative: true);

        $positionsList = $body['data'] ?? [];

        return collect($positionsList)
            ->filter(static function ($position) {
                // Only include open positions with non-zero quantity —
                // BCMath comparison preserves precision on long-decimal qty.
                return ($position['isOpen'] ?? false) === true
                    && ! Math::equal((string) ($position['currentQty'] ?? '0'), '0');
            })
            ->map(function ($position) {
                // Normalize the symbol format
                if (isset($position['symbol'])) {
                    $parts = $this->identifyBaseAndQuote($position['symbol']);
                    $position['symbol'] = $this->baseWithQuote($parts['base'], $parts['quote']);
                }

                // KuCoin uses positive qty for long, negative for short
                $qty = (float) ($position['currentQty'] ?? 0);
                $position['side'] = $qty > 0 ? 'long' : 'short';
                $position['size'] = abs($qty);

                // Add Binance-compatible fields for apiClose() compatibility
                // positionSide: LONG or SHORT (uppercase)
                $position['positionSide'] = $qty > 0 ? 'LONG' : 'SHORT';
                // positionAmt: keep original sign convention (Binance-compatible)
                $position['positionAmt'] = $qty;

                return $position;
            })
            ->keyBy(static function ($position) {
                // Preserve exchange-side identity so hedge-mode responses map
                // correctly. Kraite still permits only one side per symbol.
                $side = mb_strtoupper($position['side'] ?? 'BOTH');
                $direction = $side === 'LONG' ? 'LONG' : ($side === 'SHORT' ? 'SHORT' : 'BOTH');

                return $position['symbol'].':'.$direction;
            })
            ->toArray();
    }
}
