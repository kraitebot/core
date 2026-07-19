<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Bitget\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\Order;
use Kraite\Core\Support\ApiDataMappers\Bitget\BitgetProductContext;
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
        $properties->set('options.symbol', (string) $order->position->exchangeSymbol->asset);
        $context = BitgetProductContext::fromQuote($order->position->exchangeSymbol->quote);
        $properties->set('options.productType', $context->productType);
        $properties->set('options.marginCoin', $context->marginCoin);

        // Bitget requires holdSide for this endpoint in both position modes:
        // long/short in hedge mode, buy/sell in one-way mode.
        $properties->set('options.holdSide', $this->positionHoldSide($order->position));

        // Determine if this is a TP or SL order and set only relevant params
        $isStopLoss = $this->isStopLossOrder($order);

        if ($isStopLoss) {
            // Stop Loss parameters only
            $properties->set('options.stopLossTriggerPrice', (string) api_format_price($order->price, $order->position->exchangeSymbol));
            $properties->set('options.stopLossTriggerType', 'mark_price');
            $properties->set('options.stopLossClientOid', (string) $order->client_order_id);
            // Omit stopLossExecutePrice for market execution (default behavior)
        } else {
            // Take Profit parameters only
            $properties->set('options.stopSurplusTriggerPrice', (string) api_format_price($order->price, $order->position->exchangeSymbol));
            $properties->set('options.stopSurplusTriggerType', 'mark_price');
            $properties->set('options.stopSurplusClientOid', (string) $order->client_order_id);
            // Omit stopSurplusExecutePrice for market execution (default behavior)
        }

        return $properties;
    }

    /**
     * Resolve Bitget place-pos-tpsl response.
     *
     * Bitget V2 response structure:
     * {
     *     "code": "00000",
     *     "msg": "success",
     *     "data": [{"orderId": "123", "stopLossClientOid": "local-client-id"}]
     * }
     */
    public function resolvePlaceTpslOrderResponse(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), associative: true);
        $responseData = $data['data'] ?? [];
        $success = ($data['code'] ?? '') === '00000';
        $orderData = is_array($responseData) && array_is_list($responseData)
            ? ($responseData[0] ?? [])
            : $responseData;
        $orderData = is_array($orderData) ? $orderData : [];
        $orderId = $orderData['orderId'] ?? null;
        $clientOrderId = collect([
            $orderData['clientOid'] ?? null,
            $orderData['stopSurplusClientOid'] ?? null,
            $orderData['stopLossClientOid'] ?? null,
        ])->first(static fn (mixed $value): bool => is_string($value) && $value !== '');

        return [
            'success' => $success,
            'orderId' => is_string($orderId) && $orderId !== '' ? $orderId : null,
            'clientOid' => is_string($clientOrderId) && $clientOrderId !== '' ? $clientOrderId : null,
            'symbol' => $orderData['symbol'] ?? null,
            'holdSide' => $orderData['holdSide'] ?? null,
            'status' => $success ? 'NEW' : 'FAILED',
            '_isPositionTpsl' => true,
            '_requiresOrderIdFetch' => ! is_string($orderId) || $orderId === '',
            '_raw' => $data,
        ];
    }

    /**
     * Determine if this order is a stop-loss type.
     */
    private function isStopLossOrder(Order $order): bool
    {
        $type = mb_strtoupper(str_replace('-', '_', $order->type));

        return in_array($type, ['STOP_MARKET', 'STOP_LOSS'], true);
    }
}
