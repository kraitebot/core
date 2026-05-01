<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Bitget\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\ValueObjects\ApiProperties;

/**
 * Maps Bitget Position TP/SL operations.
 *
 * The place-pos-tpsl endpoint attaches TP/SL directly to an existing position.
 * Unlike plan orders, this doesn't require a size parameter - it automatically
 * applies to the entire position and adjusts when position size changes.
 *
 * @see https://www.bitget.com/api-doc/contract/position/Set-Position-Tpsl
 */
trait MapsPlacePosTpsl
{
    /**
     * Prepare properties for placing position TP/SL on Bitget.
     *
     * This endpoint requires an existing position and attaches TP/SL orders
     * that automatically track the position size.
     *
     * @param  Position  $position  The position to attach TP/SL to
     * @param  string  $tpPrice  Take-profit trigger price
     * @param  string  $slPrice  Stop-loss trigger price
     *
     * @see https://www.bitget.com/api-doc/contract/position/Set-Position-Tpsl
     */
    public function preparePlacePosTpslProperties(Position $position, string $tpPrice, string $slPrice): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $position);
        $properties->set('options.symbol', (string) $position->exchangeSymbol->parsed_trading_pair);
        $properties->set('options.productType', 'USDT-FUTURES');
        $properties->set('options.marginCoin', 'USDT');

        // holdSide is HEDGE-only — Bitget V2 requires it (long/short) so the
        // TP/SL targets the correct slot. In ONE-WAY mode the position is
        // identified solely by symbol+marginCoin; sending holdSide triggers
        // a "param error" rejection.
        if ($position->account->isHedgeMode()) {
            $properties->set('options.holdSide', mb_strtolower($position->direction));
        }

        // Take Profit parameters
        $properties->set('options.stopSurplusTriggerPrice', (string) api_format_price($tpPrice, $position->exchangeSymbol));
        $properties->set('options.stopSurplusTriggerType', 'mark_price');
        // Omit stopSurplusExecutePrice for market execution (default behavior)

        // Stop Loss parameters
        $properties->set('options.stopLossTriggerPrice', (string) api_format_price($slPrice, $position->exchangeSymbol));
        $properties->set('options.stopLossTriggerType', 'mark_price');
        // Omit stopLossExecutePrice for market execution (default behavior)

        return $properties;
    }

    /**
     * Resolve Bitget place position TP/SL response.
     *
     * Note: The response only confirms the operation succeeded.
     * The actual TP/SL order IDs are returned via position query (takeProfitId, stopLossId).
     *
     * Bitget V2 response structure:
     * {
     *     "code": "00000",
     *     "msg": "success",
     *     "requestTime": 1627116936176,
     *     "data": {
     *         "symbol": "BTCUSDT",
     *         "holdSide": "long"
     *     }
     * }
     */
    public function resolvePlacePosTpslResponse(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), associative: true);
        $responseData = $data['data'] ?? [];

        $success = ($data['code'] ?? '') === '00000';

        return [
            'success' => $success,
            'symbol' => $responseData['symbol'] ?? null,
            'holdSide' => $responseData['holdSide'] ?? null,
            '_raw' => $data,
        ];
    }
}
