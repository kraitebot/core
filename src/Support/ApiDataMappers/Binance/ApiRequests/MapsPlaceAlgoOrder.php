<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Binance\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Str;
use Kraite\Core\Models\Order;
use Kraite\Core\Support\ValueObjects\ApiProperties;

/**
 * Maps Binance Algo Order placement (STOP_MARKET, TAKE_PROFIT_MARKET, etc.).
 *
 * Since December 9, 2025, Binance migrated conditional orders to a new Algo Order API.
 * This trait handles the different request/response format required by /fapi/v1/algoOrder.
 *
 * Key differences from regular orders:
 * - Uses `algoType` instead of `type` (value: "CONDITIONAL")
 * - Uses `triggerPrice` instead of `stopPrice`
 * - Returns `algoId` instead of `orderId`
 * - Returns `algoStatus` instead of `status`
 *
 * STOP-MARKET orders use `closePosition=true` instead of explicit quantity.
 * This automatically closes the entire position when triggered, regardless of
 * how many limit orders have filled (WAP scenarios).
 *
 * @see https://developers.binance.com/docs/derivatives/usds-margined-futures/trade/rest-api/New-Algo-Order
 */
trait MapsPlaceAlgoOrder
{
    /**
     * Prepare properties for placing an algo order.
     *
     * Maps internal STOP-MARKET order to Binance's CONDITIONAL algo format.
     */
    public function preparePlaceAlgoOrderProperties(Order $order): ApiProperties
    {
        // Auto-generate client order id, if null.
        if (is_null($order->client_order_id)) {
            $order->updateSaving(['client_order_id' => Str::uuid()->toString()]);
        }

        $properties = new ApiProperties;
        $properties->set('relatable', $order);
        $properties->set('options.symbol', (string) $order->position->exchangeSymbol->parsed_trading_pair);
        $properties->set('options.side', (string) $this->sideType($order->side));
        $properties->set('options.positionSide', (string) $order->position_side);

        // Algo-specific parameters (per Binance docs)
        $properties->set('options.algoType', 'CONDITIONAL');
        $properties->set('options.type', 'STOP_MARKET');
        $properties->set('options.triggerPrice', (string) api_format_price($order->price, $order->position->exchangeSymbol));
        $properties->set('options.workingType', 'MARK_PRICE');
        $properties->set('options.clientAlgoId', (string) $order->client_order_id);

        // Use closePosition instead of quantity - automatically closes entire position
        // when triggered, regardless of how many limits filled (WAP scenarios)
        $properties->set('options.closePosition', 'true');

        return $properties;
    }

    /**
     * Resolve Binance algo order placement response.
     *
     * Algo orders return different fields than regular orders:
     * - `algoId` instead of `orderId`
     * - `algoStatus` instead of `status`
     * - `triggerPrice` instead of `stopPrice`
     *
     * When using closePosition=true, quantity is "0" (closes entire position).
     *
     * Example response:
     * {
     *   "algoId": 4000000047401111,
     *   "clientAlgoId": "...",
     *   "algoType": "CONDITIONAL",
     *   "symbol": "SOLUSDT",
     *   "side": "BUY",
     *   "positionSide": "SHORT",
     *   "quantity": "0",
     *   "algoStatus": "NEW",
     *   "triggerPrice": "136.0000",
     *   "workingType": "MARK_PRICE",
     *   "closePosition": true,
     *   "createTime": 1765796269439,
     *   "updateTime": 1765796269439
     * }
     */
    public function resolvePlaceAlgoOrderResponse(Response $response): array
    {
        $result = json_decode((string) $response->getBody(), associative: true);

        return [
            // Map algoId to orderId for consistent interface
            'orderId' => (string) $result['algoId'],
            'algoId' => (string) $result['algoId'],
            'clientAlgoId' => $result['clientAlgoId'] ?? null,
            'symbol' => $result['symbol'],
            'side' => $result['side'],
            'positionSide' => $result['positionSide'],
            'quantity' => $result['quantity'] ?? '0',
            'closePosition' => $result['closePosition'] ?? false,
            'status' => $this->mapAlgoStatus($result['algoStatus'] ?? 'NEW'),
            'algoStatus' => $result['algoStatus'] ?? 'NEW',
            'algoType' => $result['algoType'],
            'triggerPrice' => $result['triggerPrice'] ?? '0',
            'workingType' => $result['workingType'] ?? 'MARK_PRICE',
            '_price' => $result['triggerPrice'] ?? '0',
            '_orderType' => 'STOP_MARKET',
            '_isAlgo' => true,
            '_closePosition' => $result['closePosition'] ?? false,
            '_raw' => $result,
        ];
    }

    /**
     * Prepare properties for querying an algo order.
     */
    public function prepareAlgoOrderQueryProperties(Order $order): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $order);
        $properties->set('options.symbol', (string) $order->position->exchangeSymbol->parsed_trading_pair);
        $properties->set('options.algoId', (string) $order->exchange_order_id);

        return $properties;
    }

    /**
     * Resolve Binance algo order query response.
     */
    public function resolveAlgoOrderQueryResponse(Response $response): array
    {
        $result = json_decode((string) $response->getBody(), associative: true);

        $status = $this->mapAlgoStatus($result['algoStatus'] ?? 'NEW');
        $quantity = $result['executedQty'] ?? $result['quantity'] ?? '0';
        $price = $result['triggerPrice'] ?? '0';

        return [
            'order_id' => (string) $result['algoId'],
            'symbol' => $this->identifyBaseAndQuote($result['symbol']),
            'status' => $status,
            'price' => $price,
            '_price' => $price,
            'quantity' => $quantity,
            'type' => 'STOP_MARKET',
            '_orderType' => 'STOP_MARKET',
            'side' => $result['side'],
            '_isAlgo' => true,
            '_raw' => $result,
        ];
    }

    /**
     * Prepare properties for cancelling an algo order.
     */
    public function prepareAlgoOrderCancelProperties(Order $order): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $order);
        $properties->set('options.symbol', (string) $order->position->exchangeSymbol->parsed_trading_pair);
        $properties->set('options.algoId', (string) $order->exchange_order_id);

        return $properties;
    }

    /**
     * Resolve Binance algo order cancel response.
     */
    public function resolveAlgoOrderCancelResponse(Response $response): array
    {
        $result = json_decode((string) $response->getBody(), associative: true);

        return [
            'order_id' => (string) ($result['algoId'] ?? ''),
            'status' => $this->mapAlgoStatus($result['algoStatus'] ?? 'CANCELLED'),
            '_isAlgo' => true,
            '_raw' => $result,
        ];
    }

    /**
     * Map Binance algo status to standard order status.
     */
    private function mapAlgoStatus(string $algoStatus): string
    {
        return match ($algoStatus) {
            'NEW' => 'NEW',
            'EXECUTING' => 'NEW',
            'PARTIALLY_TRIGGERED' => 'PARTIALLY_FILLED',
            'TRIGGERED' => 'FILLED',
            'CANCELLED', 'CANCELED' => 'CANCELLED',
            'EXPIRED' => 'CANCELLED',
            'FAILED' => 'REJECTED',
            default => $algoStatus,
        };
    }
}
