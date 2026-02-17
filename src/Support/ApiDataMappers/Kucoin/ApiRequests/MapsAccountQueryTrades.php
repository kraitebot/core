<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Kucoin\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\ValueObjects\ApiProperties;

trait MapsAccountQueryTrades
{
    /**
     * Prepare properties for querying trades/fills on KuCoin Futures.
     *
     * @see https://www.kucoin.com/docs/rest/futures-trading/fills/get-filled-list
     */
    public function prepareQueryTokenTradesProperties(Position $position, ?string $fromId = null): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $position);
        $properties->set('options.symbol', (string) $position->exchangeSymbol->parsed_trading_pair);

        if ($fromId !== null) {
            $properties->set('options.lastId', $fromId);
        }

        return $properties;
    }

    /**
     * Resolve the trades/fills query response from KuCoin.
     *
     * KuCoin response structure:
     * {
     *     "code": "200000",
     *     "data": {
     *         "currentPage": 1,
     *         "pageSize": 50,
     *         "totalNum": 1,
     *         "totalPage": 1,
     *         "items": [
     *             {
     *                 "symbol": "XBTUSDTM",
     *                 "tradeId": "5ce24c16b210233c36ee321d",
     *                 "orderId": "5ce24c16b210233c36ee321c",
     *                 "side": "buy",
     *                 "liquidity": "taker",
     *                 "forceTaker": true,
     *                 "price": "8302",
     *                 "size": 10,
     *                 "value": "0.001205",
     *                 "feeRate": "0.0005",
     *                 "fixFee": "0",
     *                 "feeCurrency": "USDT",
     *                 "fee": "0.0006025",
     *                 "tradeType": "trade",
     *                 "stop": "",
     *                 "orderType": "limit",
     *                 "tradeTime": 1558334496000000000,
     *                 "settleCurrency": "USDT"
     *             }
     *         ]
     *     }
     * }
     */
    public function resolveQueryTradeResponse(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), associative: true);

        return $data['data']['items'] ?? [];
    }
}
