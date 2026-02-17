<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Kucoin\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\Order;
use Kraite\Core\Support\ValueObjects\ApiProperties;

trait MapsOrderCancel
{
    /**
     * Prepare properties for canceling an order on KuCoin Futures.
     *
     * @see https://www.kucoin.com/docs/rest/futures-trading/orders/cancel-order-by-orderid
     */
    public function prepareOrderCancelProperties(Order $order): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $order);
        $properties->set('options.orderId', (string) $order->exchange_order_id);

        return $properties;
    }

    /**
     * Resolve the cancel order response from KuCoin.
     *
     * KuCoin response structure:
     * {
     *     "code": "200000",
     *     "data": {
     *         "cancelledOrderIds": ["5bd6e9286d99522a52e458de"]
     *     }
     * }
     */
    public function resolveOrderCancelResponse(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), associative: true);
        $cancelData = $data['data'] ?? [];
        $cancelledIds = $cancelData['cancelledOrderIds'] ?? [];

        return [
            'order_id' => $cancelledIds[0] ?? null,
            'symbol' => null,
            'status' => ! empty($cancelledIds) ? 'CANCELLED' : 'NOT_FOUND',
            'price' => '0',
            '_price' => '0',
            'average_price' => '0',
            'original_quantity' => '0',
            'executed_quantity' => '0',
            'type' => null,
            '_orderType' => 'UNKNOWN',
            'side' => null,
            'original_type' => null,
            '_raw' => $data,
        ];
    }
}
