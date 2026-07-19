<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Bitget\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Str;
use Kraite\Core\Models\Order;
use Kraite\Core\Support\ApiDataMappers\Bitget\BitgetProductContext;
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
        $properties->set('options.symbol', (string) $order->position->exchangeSymbol->asset);
        $context = BitgetProductContext::fromQuote($order->position->exchangeSymbol->quote);
        $properties->set('options.productType', $context->productType);
        $properties->set('options.marginMode', mb_strtolower((string) $order->position->account->margin_mode));
        $properties->set('options.marginCoin', $context->marginCoin);
        $isHedgeMode = $order->position->account->isHedgeMode();
        $side = $isHedgeMode
            ? $this->hedgePositionSide($order->position)
            : $this->sideType($order->side);
        $properties->set('options.side', $side);
        $properties->set('options.size', (string) api_format_quantity($order->quantity, $order->position->exchangeSymbol));
        $properties->set('options.clientOid', (string) $order->client_order_id);

        // Position-mode-aware payload. In HEDGE mode V2 uses
        // tradeSide=open/close together with side=buy/sell. `posSide` belongs
        // to other Bitget API surfaces and is not accepted by V2 place-order.
        // In ONE-WAY mode tradeSide is ignored; closing-intent
        // orders MUST carry reduceOnly=YES — otherwise the same `side`
        // reopens an opposing position instead of closing the existing one.
        if ($isHedgeMode) {
            $properties->set('options.tradeSide', $this->determineTradeSide($order));
        } elseif ($this->isClosingIntent($order)) {
            $properties->set('options.reduceOnly', 'YES');
        }

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
        if ($this->isClosingIntent($order)) {
            return 'close';
        }

        return 'open';
    }

    /**
     * Whether this order is closing intent — relevant for one-way mode
     * where reduceOnly=YES must be set explicitly on closes (otherwise
     * the `side` flip would open an opposing position instead of closing
     * the existing one). Hedge mode encodes intent via tradeSide=close
     * and doesn't need this flag.
     *
     * Closing types are PROFIT-LIMIT / PROFIT-MARKET (TP), STOP-MARKET
     * (SL — though Bitget routes it via the plan-order path), and
     * MARKET-CANCEL (emergency reduce-only close). MARKET (initial
     * entry) and LIMIT (DCA) carry opening intent.
     */
    private function isClosingIntent(Order $order): bool
    {
        if ($order->reduce_only ?? false) {
            return true;
        }

        return in_array($order->type, [
            'PROFIT-LIMIT',
            'PROFIT-MARKET',
            'STOP-MARKET',
            'MARKET-CANCEL',
        ], strict: true);
    }
}
