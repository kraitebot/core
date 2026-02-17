<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Bitget\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\Order;
use Kraite\Core\Support\ValueObjects\ApiProperties;

/**
 * Maps Bitget TP/SL Order modification operations.
 *
 * The modify-tpsl-order endpoint modifies an existing position TP/SL order.
 * Used when WAP changes and we need to recalculate stop prices.
 *
 * @see https://www.bitget.com/api-doc/contract/position/Modify-Position-Tpsl
 */
trait MapsModifyTpsl
{
    /**
     * Prepare properties for modifying a TP/SL order on Bitget.
     *
     * @param  Order  $order  The TP or SL order to modify
     * @param  string  $newTriggerPrice  The new trigger price
     *
     * @see https://www.bitget.com/api-doc/contract/position/Modify-Position-Tpsl
     */
    public function prepareModifyTpslOrderProperties(Order $order, string $newTriggerPrice): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $order);
        $properties->set('options.symbol', (string) $order->position->exchangeSymbol->parsed_trading_pair);
        $properties->set('options.productType', 'USDT-FUTURES');
        $properties->set('options.marginCoin', 'USDT');

        // holdSide matches direction: 'long' or 'short'
        $holdSide = mb_strtolower($order->position->direction);
        $properties->set('options.holdSide', $holdSide);

        // Determine if this is a TP or SL order by type
        $isStopLoss = in_array($order->type, ['STOP-MARKET', 'STOP_MARKET'], true);

        if ($isStopLoss) {
            // Modifying Stop Loss
            $properties->set('options.stopLossTriggerPrice', (string) api_format_price($newTriggerPrice, $order->position->exchangeSymbol));
            $properties->set('options.stopLossTriggerType', 'mark_price');
            $properties->set('options.stopLossExecutePrice', '0');
        } else {
            // Modifying Take Profit
            $properties->set('options.stopSurplusTriggerPrice', (string) api_format_price($newTriggerPrice, $order->position->exchangeSymbol));
            $properties->set('options.stopSurplusTriggerType', 'mark_price');
            $properties->set('options.stopSurplusExecutePrice', '0');
        }

        return $properties;
    }

    /**
     * Resolve Bitget modify TP/SL order response.
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
    public function resolveModifyTpslOrderResponse(Response $response): array
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
