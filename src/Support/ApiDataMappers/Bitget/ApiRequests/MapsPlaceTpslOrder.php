<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Bitget\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\Order;
use Kraite\Core\Support\ValueObjects\ApiProperties;

/**
 * Maps Bitget position-level TP/SL recreation operations.
 *
 * Uses place-pos-tpsl endpoint with only the relevant TP or SL parameters
 * to recreate a single cancelled order as a position-level order (not partial).
 *
 * This creates orders that show "Position SL-Market / All closable" in the UI,
 * matching the original orders created during position activation.
 *
 * @see https://www.bitget.com/api-doc/contract/plan/Place-Pos-Tpsl-Order
 */
trait MapsPlaceTpslOrder
{
    /**
     * Prepare properties for placing a single TP or SL via place-pos-tpsl.
     *
     * Only sets the relevant parameters (TP or SL) based on order type,
     * leaving the other unset to avoid affecting existing orders.
     *
     * @see https://www.bitget.com/api-doc/contract/plan/Place-Pos-Tpsl-Order
     */
    public function preparePlaceTpslOrderProperties(Order $order): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $order);
        $properties->set('options.symbol', (string) $order->position->exchangeSymbol->parsed_trading_pair);
        $properties->set('options.productType', 'USDT-FUTURES');
        $properties->set('options.marginCoin', 'USDT');

        // holdSide must match the position direction
        $holdSide = mb_strtolower($order->position->direction);
        $properties->set('options.holdSide', $holdSide);

        // Determine if this is a TP or SL order and set only relevant params
        $isStopLoss = $this->isStopLossOrder($order);

        if ($isStopLoss) {
            // Stop Loss parameters only
            $properties->set('options.stopLossTriggerPrice', (string) api_format_price($order->price, $order->position->exchangeSymbol));
            $properties->set('options.stopLossTriggerType', 'mark_price');
            // Omit stopLossExecutePrice for market execution (default behavior)
        } else {
            // Take Profit parameters only
            $properties->set('options.stopSurplusTriggerPrice', (string) api_format_price($order->price, $order->position->exchangeSymbol));
            $properties->set('options.stopSurplusTriggerType', 'mark_price');
            // Omit stopSurplusExecutePrice for market execution (default behavior)
        }

        return $properties;
    }

    /**
     * Resolve Bitget place-pos-tpsl response.
     *
     * Note: This endpoint doesn't return the orderId directly.
     * The orderId must be fetched by querying the position afterwards.
     *
     * Bitget V2 response structure:
     * {
     *     "code": "00000",
     *     "msg": "success",
     *     "data": {
     *         "symbol": "MASKUSDT",
     *         "holdSide": "long"
     *     }
     * }
     */
    public function resolvePlaceTpslOrderResponse(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), associative: true);
        $responseData = $data['data'] ?? [];

        $success = ($data['code'] ?? '') === '00000';

        return [
            'success' => $success,
            'orderId' => null, // Must be fetched from position query
            'symbol' => $responseData['symbol'] ?? null,
            'holdSide' => $responseData['holdSide'] ?? null,
            'status' => $success ? 'NEW' : 'FAILED',
            '_isPositionTpsl' => true,
            '_requiresOrderIdFetch' => true,
            '_raw' => $data,
        ];
    }

    /**
     * Determine if this order is a stop-loss type.
     */
    private function isStopLossOrder(Order $order): bool
    {
        $type = strtoupper(str_replace('-', '_', $order->type));

        return in_array($type, ['STOP_MARKET', 'STOP_LOSS'], true);
    }
}
