<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Bybit\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\ValueObjects\ApiProperties;

trait MapsAccountQueryTrades
{
    /**
     * Prepare properties for querying trade/execution history on Bybit.
     *
     * @see https://bybit-exchange.github.io/docs/v5/order/execution
     */
    public function prepareQueryTokenTradesProperties(Position $position, ?string $cursor = null): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $position);
        $properties->set('options.category', 'linear');
        $properties->set('options.symbol', (string) $position->exchangeSymbol->parsed_trading_pair);
        $properties->set('options.limit', 50);

        if ($cursor) {
            $properties->set('options.cursor', $cursor);
        }

        return $properties;
    }

    /**
     * Resolve the trade/execution history response from Bybit.
     *
     * Bybit V5 response structure (GET /v5/execution/list):
     * {
     *     "retCode": 0,
     *     "result": {
     *         "category": "linear",
     *         "list": [{
     *             "symbol": "ETHPERP",
     *             "orderId": "1666a13a-...",
     *             "orderLinkId": "",
     *             "side": "Sell",
     *             "orderPrice": "1500",
     *             "orderQty": "0.10",
     *             "execFee": "0.0007215",
     *             "execId": "2e5a09ee-...",
     *             "execPrice": "1203",
     *             "execQty": "0.10",
     *             "execTime": "1669196423581",
     *             "feeRate": "0.0006",
     *             "isMaker": false,
     *             ...
     *         }],
     *         "nextPageCursor": "..."
     *     }
     * }
     */
    public function resolveQueryTradeResponse(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), associative: true);
        $result = $data['result'] ?? [];
        $trades = $result['list'] ?? [];

        return array_map(callback: static function (array $trade): array {
            return [
                'tradeId' => $trade['execId'] ?? null,
                'orderId' => $trade['orderId'] ?? null,
                'symbol' => $trade['symbol'] ?? null,
                'side' => isset($trade['side']) ? mb_strtoupper($trade['side']) : null,
                'price' => $trade['execPrice'] ?? '0',
                'quantity' => $trade['execQty'] ?? '0',
                'fee' => $trade['execFee'] ?? '0',
                'feeRate' => $trade['feeRate'] ?? '0',
                'isMaker' => $trade['isMaker'] ?? false,
                'timestamp' => $trade['execTime'] ?? null,
                '_raw' => $trade,
            ];
        }, array: $trades);
    }
}
