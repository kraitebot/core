<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Kucoin\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\ValueObjects\ApiProperties;

trait MapsCancelOrders
{
    /**
     * Prepare properties for canceling all orders on KuCoin Futures.
     *
     * @see https://www.kucoin.com/docs/rest/futures-trading/orders/cancel-multiple-futures-limit-orders
     */
    public function prepareCancelOrdersProperties(Position $position): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $position);
        $properties->set('options.symbol', (string) $position->exchangeSymbol->parsed_trading_pair);

        return $properties;
    }

    /**
     * Resolve the cancel all orders response from KuCoin.
     *
     * KuCoin response structure:
     * {
     *     "code": "200000",
     *     "data": {
     *         "cancelledOrderIds": ["5bd6e9286d99522a52e458de", "5bd6e9286d99522a52e458df"]
     *     }
     * }
     */
    public function resolveCancelOrdersResponse(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), associative: true);

        return $data['data'] ?? [];
    }
}
