<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Binance\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\Order;
use Kraite\Core\Support\Math;
use Kraite\Core\Support\ValueObjects\ApiProperties;

trait MapsOrderQuery
{
    public function prepareOrderQueryProperties(Order $order): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $order);
        $properties->set('options.symbol', (string) $order->position->exchangeSymbol->parsed_trading_pair);
        $properties->set('options.orderId', (string) $order->exchange_order_id);

        return $properties;
    }

    public function resolveOrderQueryResponse(Response $response): array
    {
        $result = json_decode((string) $response->getBody(), associative: true);

        $raw = $result;

        // Special cases.
        if ($result['type'] === 'STOP_MARKET') {
            $price = $result['stopPrice'];
            // For STOP_MARKET: use executedQty if triggered/filled, otherwise origQty
            $quantity = Math::gt($result['executedQty'], '0') ? $result['executedQty'] : $result['origQty'];
        } else {
            // Binance omits `avgPrice` on payloads for never-filled orders —
            // already fataled production twice on the cancel and modify
            // mappers (see MapsOrderCancel / MapsOrderModify). Same guard
            // here so a query response without the key degrades to `price`
            // instead of crashing the sync worker mid-reconciliation.
            $avgPrice = $result['avgPrice'] ?? '0';
            $price = Math::gt($avgPrice, '0') ? $avgPrice : $result['price'];
            $quantity = Math::gt($result['executedQty'], '0') ? $result['executedQty'] : $result['origQty'];
        }

        if ($result['status'] === 'CANCELED') {
            $result['status'] = 'CANCELLED';
        }

        return [
            // Exchange order id.
            'order_id' => $result['orderId'],

            // [0 => 'RENDER', 1 => 'USDT']
            'symbol' => $this->identifyBaseAndQuote($result['symbol']),

            // NEW, FILLED, CANCELED, PARTIALLY_FILLED
            'status' => $result['status'],

            'price' => $price,
            '_price' => $this->computeOrderQueryPrice($result),
            'quantity' => $quantity,
            'type' => $result['type'],
            '_orderType' => $this->canonicalOrderType($result),
            'side' => $result['side'],

            '_raw' => $raw,
        ];
    }

    /**
     * Compute the effective display price based on order type.
     *
     * - LIMIT: uses price
     * - MARKET: uses avgPrice (if filled) or 0
     * - STOP_MARKET, STOP_LIMIT, TAKE_PROFIT, TAKE_PROFIT_LIMIT, TAKE_PROFIT_MARKET: uses stopPrice
     * - TRAILING_STOP_MARKET: uses activatePrice or stopPrice
     */
    private function computeOrderQueryPrice(array $order): string
    {
        $type = $order['type'] ?? '';
        $price = $order['price'] ?? '0';
        $stopPrice = $order['stopPrice'] ?? '0';
        $avgPrice = $order['avgPrice'] ?? '0';
        $activatePrice = $order['activatePrice'] ?? '0';

        return match ($type) {
            'LIMIT' => $price,
            'MARKET' => Math::gt((string) $avgPrice, '0') ? $avgPrice : '0',
            'STOP_MARKET', 'STOP_LIMIT', 'STOP', 'TAKE_PROFIT', 'TAKE_PROFIT_LIMIT', 'TAKE_PROFIT_MARKET' => $stopPrice,
            'TRAILING_STOP_MARKET' => Math::gt((string) $activatePrice, '0') ? $activatePrice : $stopPrice,
            default => Math::gt((string) $price, '0') ? $price : $stopPrice,
        };
    }
}
