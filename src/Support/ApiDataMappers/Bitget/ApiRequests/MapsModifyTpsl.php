<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Bitget\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\Order;
use Kraite\Core\Support\ApiDataMappers\Bitget\BitgetProductContext;
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
     * Classic position TP/SL orders are not safely modifiable through its
     * v2 per-order endpoint, so Classic correction uses `place-pos-tpsl`.
     * Unified accounts translate this canonical shape to v3
     * `modify-strategy-order`, where each TP/SL leg is independently mutable.
     *
     * @param  Order  $order  The TP or SL order to modify
     * @param  string  $newTriggerPrice  The new trigger price
     *
     * @see https://www.bitget.com/api-doc/contract/position/Modify-Position-Tpsl
     */
    public function prepareModifyTpslOrderProperties(
        Order $order,
        string $newTriggerPrice,
        ?string $quantity = null,
    ): ApiProperties {
        $properties = new ApiProperties;
        $properties->set('relatable', $order);
        $properties->set('unifiedQuantity', $quantity);
        $properties->set('options.symbol', (string) $order->position->exchangeSymbol->asset);
        $context = BitgetProductContext::fromQuote($order->position->exchangeSymbol->quote);
        $properties->set('options.productType', $context->productType);
        $properties->set('options.marginCoin', $context->marginCoin);

        // holdSide is HEDGE-only — required to disambiguate LONG vs SHORT
        // when modifying a TP/SL. ONE-WAY targets the symbol's single slot
        // and the param must be omitted.
        if ($order->position->account->isHedgeMode()) {
            $properties->set('options.holdSide', mb_strtolower($order->position->direction));
        }

        // Determine if this is a TP or SL order by type
        $type = mb_strtoupper(str_replace('-', '_', $order->type));
        $isStopLoss = in_array($type, ['STOP_MARKET', 'STOP_LOSS'], true);

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
