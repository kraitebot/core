<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Bitget\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\ValueObjects\ApiProperties;

trait MapsAccountQueryTrades
{
    public function prepareQueryTokenTradesProperties(Position $position, ?string $orderId = null): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $position);
        $properties->set('options.productType', 'USDT-FUTURES');
        $properties->set('options.symbol', (string) $position->exchangeSymbol->parsed_trading_pair);

        if ($orderId !== null) {
            $properties->set('options.orderId', $orderId);
        }

        return $properties;
    }

    /**
     * Resolves BitGet order fills response.
     *
     * BitGet V2 response structure:
     * {
     *     "code": "00000",
     *     "data": {
     *         "fillList": [
     *             {
     *                 "tradeId": "123456",
     *                 "symbol": "BTCUSDT",
     *                 "orderId": "789",
     *                 "price": "40000",
     *                 "baseVolume": "0.001",
     *                 "feeDetail": {...},
     *                 "side": "buy",
     *                 "profit": "0",
     *                 "tradeSide": "open",
     *                 "cTime": "1627116936176"
     *             }
     *         ],
     *         "endId": "123456"
     *     }
     * }
     */
    public function resolveQueryTradeResponse(Response $response): array
    {
        $body = json_decode((string) $response->getBody(), associative: true);

        return $body['data']['fillList'] ?? [];
    }
}
