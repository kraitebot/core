<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Binance\ApiRequests;

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
        $properties->set('options.side', (string) $this->sideType($order->side));
        $properties->set('options.newClientOrderId', (string) $order->client_order_id);
        $properties->set('options.quantity', (string) api_format_quantity($order->quantity, $order->position->exchangeSymbol));

        // Position-mode-aware payload. In HEDGE mode every order MUST carry
        // positionSide=LONG/SHORT; (side, positionSide) implies open vs close
        // intent. In ONE-WAY mode positionSide MUST be omitted (Binance error
        // -4061 if sent) and closing-intent orders MUST carry reduceOnly=true
        // — otherwise the same `side` reopens an opposing position instead of
        // closing the existing one.
        if ($order->position->account->isHedgeMode()) {
            $properties->set('options.positionSide', (string) $order->position_side);
        } elseif ($this->isClosingIntent($order)) {
            $properties->set('options.reduceOnly', 'true');
        }

        switch ($order->type) {
            // A profit order type limit.
            case 'PROFIT-LIMIT':
                $properties->set('options.timeInForce', 'GTC');
                $properties->set('options.type', 'LIMIT');
                $properties->set('options.price', (string) api_format_price($order->price, $order->position->exchangeSymbol));
                break;

            case 'LIMIT':
                $properties->set('options.timeInForce', 'GTC');
                $properties->set('options.type', 'LIMIT');
                $properties->set('options.price', (string) api_format_price($order->price, $order->position->exchangeSymbol));
                break;

            case 'MARKET':
            case 'MARKET-MAGNET':
            case 'MARKET-CANCEL':
                $properties->set('options.type', 'MARKET');
                break;

            case 'STOP-MARKET':
                $properties->set('options.type', 'STOP_MARKET');
                $properties->set('options.timeInForce', 'GTC');
                $properties->set('options.stopPrice', (string) api_format_price($order->price, $order->position->exchangeSymbol));
                break;
        }

        return $properties;
    }

    public function resolvePlaceOrderResponse(Response $response): array
    {
        $order = json_decode((string) $response->getBody(), associative: true);
        $order['_price'] = $this->findPlaceOrderPrice($order);
        $order['_orderType'] = $this->canonicalOrderType($order);

        return $order;
    }

    /**
     * Whether this order is closing intent — relevant for one-way mode
     * where reduceOnly must be set explicitly on closes (otherwise the
     * `side` flip would open an opposing position instead of closing
     * the existing one). Hedge mode encodes intent via positionSide and
     * doesn't need this flag.
     *
     * Closing types are PROFIT-LIMIT / PROFIT-MARKET (TP), STOP-MARKET
     * (SL via algo), MARKET-CANCEL (emergency reduce-only close).
     * MARKET (initial entry) and LIMIT (DCA) carry opening intent.
     *
     * Note: STOP-MARKET on Binance routes through the algo path
     * (preparePlaceAlgoOrderProperties), so this branch only matters
     * for non-Binance exchanges that share this trait — listing it
     * here keeps the intent table complete.
     */
    private function isClosingIntent(Order $order): bool
    {
        return in_array($order->type, [
            'PROFIT-LIMIT',
            'PROFIT-MARKET',
            'STOP-MARKET',
            'MARKET-CANCEL',
        ], strict: true);
    }

    /**
     * Finds the effective display price based on order type, given the api data.
     *
     * - LIMIT: uses price
     * - MARKET: uses avgPrice (if filled) or 0
     * - STOP_MARKET, STOP_LIMIT, TAKE_PROFIT, TAKE_PROFIT_LIMIT, TAKE_PROFIT_MARKET: uses stopPrice
     * - TRAILING_STOP_MARKET: uses activatePrice or stopPrice
     */
    private function findPlaceOrderPrice(array $order): string
    {
        $type = $order['type'] ?? '';
        $price = $order['price'] ?? '0';
        $stopPrice = $order['stopPrice'] ?? '0';
        $avgPrice = $order['avgPrice'] ?? '0';
        $activatePrice = $order['activatePrice'] ?? '0';

        return match ($type) {
            'LIMIT' => $price,
            'MARKET' => (float) $avgPrice > 0 ? $avgPrice : '0',
            'STOP_MARKET', 'STOP_LIMIT', 'STOP', 'TAKE_PROFIT', 'TAKE_PROFIT_LIMIT', 'TAKE_PROFIT_MARKET' => $stopPrice,
            'TRAILING_STOP_MARKET' => (float) $activatePrice > 0 ? $activatePrice : $stopPrice,
            default => (float) $price > 0 ? $price : $stopPrice,
        };
    }
}
