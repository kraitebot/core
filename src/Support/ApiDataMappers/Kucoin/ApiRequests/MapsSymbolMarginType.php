<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Kucoin\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\ValueObjects\ApiProperties;

trait MapsSymbolMarginType
{
    /**
     * Prepare properties for updating margin mode on KuCoin Futures.
     *
     * Note: KuCoin uses ISOLATED/CROSS while Binance uses ISOLATED/CROSSED.
     *
     * @see https://www.kucoin.com/docs-new/rest/futures-trading/positions/switch-margin-mode
     */
    public function prepareUpdateMarginTypeProperties(Position $position): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $position);
        $properties->set('options.symbol', (string) $position->exchangeSymbol->parsed_trading_pair);

        // Get margin mode from account and convert to KuCoin format (ISOLATED/CROSS)
        // Note: KuCoin uses 'CROSS' not 'CROSSED'
        $marginMode = mb_strtoupper($position->account->margin_mode);
        $apiValue = $marginMode === 'CROSSED' ? 'CROSS' : $marginMode;
        $properties->set('options.marginMode', $apiValue);

        return $properties;
    }

    /**
     * Resolve the update margin type response from KuCoin.
     *
     * KuCoin response structure:
     * {
     *     "code": "200000",
     *     "data": true
     * }
     */
    public function resolveUpdateMarginTypeResponse(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), associative: true);

        return [
            'success' => ($data['data'] ?? false) === true,
            '_raw' => $data,
        ];
    }
}
