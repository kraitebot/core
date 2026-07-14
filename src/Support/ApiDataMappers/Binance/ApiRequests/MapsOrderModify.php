<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Binance\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\order;
use Kraite\Core\Support\Math;
use Kraite\Core\Support\ValueObjects\ApiProperties;

trait MapsOrderModify
{
    public function prepareOrderModifyProperties(order $order, $quantity, $price): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $order);
        $properties->set('options.orderId', (string) $order->exchange_order_id);
        $properties->set('options.side', (string) $order->side);
        $properties->set('options.symbol', (string) $order->position->exchangeSymbol->parsedTradingPair);
        $properties->set('options.quantity', (string) $quantity);
        $properties->set('options.price', (string) $price);

        return $properties;
    }

    public function resolveOrderModifyResponse(Response $response): array
    {
        $result = json_decode((string) $response->getBody(), associative: true);

        return [
            'order_id' => $result['orderId'],
            'symbol' => $this->identifyBaseAndQuote($result['symbol']),
            'status' => $result['status'],
            'price' => $result['price'],
            '_price' => $this->computeOrderModifyPrice($result),
            // Binance omits `avgPrice` on modify responses for never-filled
            // orders — a PUT that amends a resting NEW take-profit comes back
            // without the key. The unguarded read fataled the worker AFTER the
            // modify had already landed exchange-side, which failed the WAP
            // take-profit resize (CalculateWapAndModifyProfitOrderJob) and left
            // the position's TP sized for the pre-WAP fill set while the
            // exchange carried the full averaged quantity (2026-07-13,
            // position #394 FILUSDT — TP stuck at 47.3 while the position was
            // 141.9). Mirrors the guard on MapsOrderCancel::resolveOrderCancelResponse.
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
    private function computeOrderModifyPrice(array $order): string
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
