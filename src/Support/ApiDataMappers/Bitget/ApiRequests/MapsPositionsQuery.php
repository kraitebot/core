<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Bitget\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\Account;
use Kraite\Core\Support\ValueObjects\ApiProperties;

trait MapsPositionsQuery
{
    public function prepareQueryPositionsProperties(Account $account): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $account);

        // BitGet V2 requires productType for futures
        $properties->set('options.productType', 'USDT-FUTURES');

        return $properties;
    }

    /**
     * Resolves BitGet open positions response.
     *
     * BitGet V2 response structure:
     * {
     *     "code": "00000",
     *     "msg": "success",
     *     "requestTime": 1627116936176,
     *     "data": [
     *         {
     *             "marginCoin": "USDT",
     *             "symbol": "BTCUSDT",
     *             "holdSide": "long",
     *             "openDelegateSize": "0",
     *             "marginSize": "10.5",
     *             "available": "1.5",
     *             "locked": "0",
     *             "total": "1.5",
     *             "leverage": "10",
     *             "achievedProfits": "0",
     *             "openPriceAvg": "40000",
     *             "marginMode": "crossed",
     *             "posMode": "hedge_mode",
     *             "unrealizedPL": "50.5",
     *             "liquidationPrice": "35000",
     *             "keepMarginRate": "0.004",
     *             "markPrice": "40500",
     *             "breakEvenPrice": "40010",
     *             "totalFee": "1.5",
     *             "deductedFee": "0",
     *             "cTime": "1627116936176"
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
                // Only include positions with non-zero total size
                return (float) ($position['total'] ?? 0) !== 0.0;
            })
            ->map(static function ($position) {
                // Normalize the symbol format (BitGet uses simple format already)
                $symbol = $position['symbol'] ?? '';

                // BitGet uses holdSide: 'long' or 'short'
                $holdSide = $position['holdSide'] ?? 'long';
                $position['side'] = $holdSide;
                $size = abs((float) ($position['total'] ?? 0));
                $position['size'] = $size;

                // Add Binance-compatible fields for apiClose() compatibility
                // positionSide: LONG or SHORT (uppercase)
                $position['positionSide'] = mb_strtoupper($holdSide);
                // positionAmt: positive for long, negative for short (Binance convention)
                $position['positionAmt'] = $holdSide === 'short' ? -$size : $size;

                // Position TP/SL IDs (populated when place-pos-tpsl is used)
                $position['takeProfitId'] = $position['takeProfitId'] ?? null;
                $position['stopLossId'] = $position['stopLossId'] ?? null;

                return $position;
            })
            ->keyBy(static function ($position) {
                // Key by symbol:direction to support hedge mode (LONG + SHORT on same symbol)
                $side = mb_strtoupper($position['side'] ?? 'BOTH');
                $direction = $side === 'LONG' ? 'LONG' : ($side === 'SHORT' ? 'SHORT' : 'BOTH');

                return $position['symbol'] . ':' . $direction;
            })
            ->toArray();
    }
}
