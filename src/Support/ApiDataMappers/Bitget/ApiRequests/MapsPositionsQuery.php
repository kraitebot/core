<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Bitget\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\Account;
use Kraite\Core\Support\ApiDataMappers\Bitget\BitgetProductContext;
use Kraite\Core\Support\Math;
use Kraite\Core\Support\ValueObjects\ApiProperties;

trait MapsPositionsQuery
{
    public function prepareQueryPositionsProperties(Account $account): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $account);

        $properties->set(
            'options.productType',
            BitgetProductContext::fromQuote($account->trading_quote)->productType
        );

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

        $rawData = $body['data'] ?? [];

        // Classic (v2) responds with a plain list; unified (v3) wraps the
        // list in an object and renames holdSide→posSide / posMode→holdMode.
        // Normalize v3 entries back to the v2 names so the shared pipeline
        // below applies to both generations.
        if (is_array($rawData) && ! array_is_list($rawData)) {
            $positionsList = collect($rawData['list'] ?? [])
                ->map(static function ($position) {
                    $position['holdSide'] ??= $position['posSide'] ?? 'long';
                    $position['posMode'] ??= $position['holdMode'] ?? '';

                    return $position;
                })
                ->all();
        } else {
            $positionsList = $rawData;
        }

        return collect($positionsList)
            ->filter(static function ($position) {
                // Only include positions with non-zero total size — BCMath
                // comparison preserves precision on long-decimal sizes.
                return ! Math::equal((string) ($position['total'] ?? '0'), '0');
            })
            ->map(static function ($position) {
                // BitGet uses holdSide: 'long' or 'short' (always populated,
                // even in one-way mode it reflects the actual direction)
                $holdSide = $position['holdSide'] ?? 'long';
                $position['side'] = $holdSide;
                // size/positionAmt remain float for downstream contract.
                // Full BCMath migration of the position-quantity surface
                // is a follow-up — touching the mapper alone breaks every
                // consumer that does float math on positionAmt.
                $size = abs((float) ($position['total'] ?? 0));
                $position['size'] = $size;

                // Add Binance-compatible fields for apiClose() compatibility.
                // Hedge mode reports LONG/SHORT (independent slots);
                // one-way mode reports BOTH (single slot per symbol) so
                // consumers that key by `symbol:BOTH` (Binance convention)
                // can locate the position regardless of exchange.
                $isOneWay = ($position['posMode'] ?? '') === 'one_way_mode';
                $position['positionSide'] = $isOneWay ? 'BOTH' : mb_strtoupper($holdSide);
                // positionAmt: positive for long, negative for short (Binance convention)
                $position['positionAmt'] = $holdSide === 'short' ? -$size : $size;

                // Position TP/SL IDs (populated when place-pos-tpsl is used)
                $position['takeProfitId'] = $position['takeProfitId'] ?? null;
                $position['stopLossId'] = $position['stopLossId'] ?? null;

                return $position;
            })
            ->keyBy(static function ($position) {
                // Key by symbol:direction to mirror Binance:
                //   hedge mode  → symbol:LONG / symbol:SHORT (independent slots)
                //   one-way     → symbol:BOTH (single slot per symbol)
                return $position['symbol'].':'.$position['positionSide'];
            })
            ->toArray();
    }
}
