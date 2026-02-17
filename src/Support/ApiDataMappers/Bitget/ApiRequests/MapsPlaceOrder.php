<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Bitget\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Str;
use Kraite\Core\Models\Order;
use Kraite\Core\Support\ValueObjects\ApiProperties;

trait MapsPlaceOrder
{
    public function preparePlaceOrderProperties(Order $order): ApiProperties
    {
        // Auto-generate client order id, if null.
        if (is_null($order->client_order_id)) {
            $order->updateSaving(['client_order_id' => Str::uuid()->toString()]);
        }

        $properties = new ApiProperties;
        $properties->set('relatable', $order);
        $properties->set('options.symbol', (string) $order->position->exchangeSymbol->parsed_trading_pair);
        $properties->set('options.productType', 'USDT-FUTURES');
        $properties->set('options.marginMode', 'crossed');
        $properties->set('options.marginCoin', 'USDT');
        $properties->set('options.side', (string) $this->sideType($order->side));
        $properties->set('options.tradeSide', $this->determineTradeSide($order));
        $properties->set('options.posSide', $this->determinePosSide($order));
        $properties->set('options.size', (string) api_format_quantity($order->quantity, $order->position->exchangeSymbol));
        $properties->set('options.clientOid', (string) $order->client_order_id);

        switch ($order->type) {
            case 'PROFIT-LIMIT':
            case 'LIMIT':
                $properties->set('options.orderType', 'limit');
                $properties->set('options.force', 'gtc');
                $properties->set('options.price', (string) api_format_price($order->price, $order->position->exchangeSymbol));
                break;

            case 'MARKET':
            case 'MARKET-MAGNET':
            case 'MARKET-CANCEL':
                $properties->set('options.orderType', 'market');
                break;

            case 'STOP-MARKET':
                // BitGet uses plan orders for stop-market orders.
                // This would need to use the plan order endpoint instead.
                // For now, set as market with a note.
                $properties->set('options.orderType', 'market');
                break;
        }

        return $properties;
    }

    /**
     * Resolves BitGet place order response.
     *
     * BitGet V2 response structure:
     * {
     *     "code": "00000",
     *     "msg": "success",
     *     "data": {
     *         "orderId": "121211212122",
     *         "clientOid": "121211212122"
     *     }
     * }
     */
    public function resolvePlaceOrderResponse(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), associative: true);
        $order = $data['data'] ?? [];

        // BitGet returns minimal data on place order, so we add computed fields.
        $order['_price'] = '0';
        $order['_orderType'] = 'UNKNOWN';

        return $order;
    }

    /**
     * Determine if this order is opening or closing a position.
     *
     * 'open' - Opening a new position or adding to existing
     * 'close' - Reducing or closing an existing position
     */
    private function determineTradeSide(Order $order): string
    {
        // MARKET-CANCEL orders are specifically for closing positions
        if ($order->type === 'MARKET-CANCEL') {
            return 'close';
        }

        // If the order is marked as reduceOnly, it's closing.
        if ($order->reduce_only ?? false) {
            return 'close';
        }

        // Default to opening.
        return 'open';
    }

    /**
     * Determine the position side for hedge mode.
     *
     * BitGet requires posSide in hedge mode to know which position to affect.
     * 'long' - Long position
     * 'short' - Short position
     */
    private function determinePosSide(Order $order): string
    {
        // Use the order's position_side if set (from apiClose)
        if (! empty($order->position_side)) {
            return mb_strtolower($order->position_side);
        }

        // Derive from the position's direction
        $direction = $order->position->direction ?? 'LONG';

        return mb_strtolower($direction);
    }
}
