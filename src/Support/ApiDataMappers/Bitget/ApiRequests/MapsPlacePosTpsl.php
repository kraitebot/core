<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Bitget\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\ApiDataMappers\Bitget\BitgetProductContext;
use Kraite\Core\Support\ValueObjects\ApiProperties;

/**
 * Maps Bitget Position TP/SL operations.
 *
 * The place-pos-tpsl endpoint attaches TP/SL directly to an existing position.
 * Unlike plan orders, this doesn't require a size parameter - it automatically
 * applies to the entire position and adjusts when position size changes.
 *
 * @see https://www.bitget.com/api-doc/classic/contract/plan/Place-Pos-Tpsl-Order
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
     * @see https://www.bitget.com/api-doc/classic/contract/plan/Place-Pos-Tpsl-Order
     */
    public function preparePlacePosTpslProperties(
        Position $position,
        string $tpPrice,
        string $slPrice,
        ?string $profitClientOrderId = null,
        ?string $stopLossClientOrderId = null,
    ): ApiProperties {
        $properties = new ApiProperties;
        $properties->set('relatable', $position);
        $properties->set('options.symbol', (string) $position->exchangeSymbol->asset);
        $context = BitgetProductContext::fromQuote($position->exchangeSymbol->quote);
        $properties->set('options.productType', $context->productType);
        $properties->set('options.marginCoin', $context->marginCoin);

        // Bitget requires holdSide for this endpoint in both position modes:
        // long/short in hedge mode, buy/sell in one-way mode.
        $properties->set('options.holdSide', $this->positionHoldSide($position));

        // Take Profit parameters
        $properties->set('options.stopSurplusTriggerPrice', (string) api_format_price($tpPrice, $position->exchangeSymbol));
        $properties->set('options.stopSurplusTriggerType', 'mark_price');
        if ($profitClientOrderId !== null && $profitClientOrderId !== '') {
            $properties->set('options.stopSurplusClientOid', $profitClientOrderId);
        }
        // Omit stopSurplusExecutePrice for market execution (default behavior)

        // Stop Loss parameters
        $properties->set('options.stopLossTriggerPrice', (string) api_format_price($slPrice, $position->exchangeSymbol));
        $properties->set('options.stopLossTriggerType', 'mark_price');
        if ($stopLossClientOrderId !== null && $stopLossClientOrderId !== '') {
            $properties->set('options.stopLossClientOid', $stopLossClientOrderId);
        }
        // Omit stopLossExecutePrice for market execution (default behavior)

        return $properties;
    }

    /**
     * Resolve Bitget place position TP/SL response.
     *
     * Bitget V2 response structure:
     * {
     *     "code": "00000",
     *     "msg": "success",
     *     "requestTime": 1627116936176,
     *     "data": [
     *         {"orderId": "123", "stopSurplusClientOid": "profit-client-id"},
     *         {"orderId": "456", "stopLossClientOid": "stop-client-id"}
     *     ]
     * }
     */
    public function resolvePlacePosTpslResponse(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), associative: true);
        $responseData = $data['data'] ?? [];
        $success = ($data['code'] ?? '') === '00000';
        $ordersByClientOid = [];
        $candidateOrderIds = [];

        if (is_array($responseData) && array_is_list($responseData)) {
            foreach ($responseData as $orderData) {
                if (! is_array($orderData)) {
                    continue;
                }

                $orderId = $orderData['orderId'] ?? null;
                if (! is_string($orderId) || $orderId === '') {
                    continue;
                }

                foreach (['clientOid', 'stopSurplusClientOid', 'stopLossClientOid'] as $clientIdField) {
                    $clientOrderId = $orderData[$clientIdField] ?? null;

                    if (is_string($clientOrderId) && $clientOrderId !== '') {
                        $candidateOrderIds[$clientOrderId][$orderId] = true;
                    }
                }
            }
        }

        foreach ($candidateOrderIds as $clientOrderId => $orderIds) {
            if (count($orderIds) === 1) {
                $ordersByClientOid[$clientOrderId] = array_key_first($orderIds);
            }
        }

        return [
            'success' => $success,
            'ordersByClientOid' => $ordersByClientOid,
            'symbol' => is_array($responseData) && ! array_is_list($responseData)
                ? ($responseData['symbol'] ?? null)
                : null,
            'holdSide' => is_array($responseData) && ! array_is_list($responseData)
                ? ($responseData['holdSide'] ?? null)
                : null,
            '_raw' => $data,
        ];
    }
}
