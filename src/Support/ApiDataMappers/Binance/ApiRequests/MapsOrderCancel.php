<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Binance\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\Order;
use Kraite\Core\Support\Math;
use Kraite\Core\Support\ValueObjects\ApiProperties;

trait MapsOrderCancel
{
    public function prepareOrderCancelProperties(Order $order): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $order);
        $properties->set('options.orderId', (string) $order->exchange_order_id);
        $properties->set('options.symbol', (string) $order->position->exchangeSymbol->parsed_trading_pair);

        return $properties;
    }

    public function resolveOrderCancelResponse(Response $response): array
    {
        $result = json_decode((string) $response->getBody(), associative: true);

        return [
            'order_id' => $result['orderId'],
            'symbol' => $this->identifyBaseAndQuote($result['symbol']),
            'status' => $result['status'],
            'price' => $result['price'],
            '_price' => $this->computeOrderCancelPrice($result),
            // Binance omits avgPrice on cancel responses for never-filled
            // orders — an unguarded read fatals the worker AFTER the cancel
            // landed exchange-side, failing the close chain and orphaning
            // the position's remaining ladder (2026-07-10 night, pos #176).
            'average_price' => $result['avgPrice'] ?? '0',
            'original_quantity' => $result['origQty'],
            'executed_quantity' => $result['executedQty'],
            'type' => $result['type'],
            '_orderType' => $this->canonicalOrderType($result),
            'side' => $result['side'],
            'original_type' => $result['origType'],
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
    private function computeOrderCancelPrice(array $order): string
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
