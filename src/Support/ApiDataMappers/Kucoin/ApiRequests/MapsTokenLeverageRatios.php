<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Kucoin\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\ValueObjects\ApiProperties;

trait MapsTokenLeverageRatios
{
    /**
     * Prepare properties for updating leverage on KuCoin Futures.
     *
     * @see https://www.kucoin.com/docs/rest/futures-trading/positions/modify-cross-margin-leverage
     */
    public function prepareUpdateLeverageRatioProperties(Position $position, int $leverage): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $position);
        $properties->set('options.symbol', (string) $position->exchangeSymbol->parsed_trading_pair);
        $properties->set('options.leverage', (string) $leverage);

        return $properties;
    }

    /**
     * Resolve the update leverage response from KuCoin.
     *
     * KuCoin response structure:
     * {
     *     "code": "200000",
     *     "data": true
     * }
     */
    public function resolveUpdateLeverageRatioResponse(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), associative: true);

        return [
            'success' => ($data['data'] ?? false) === true,
            '_raw' => $data,
        ];
    }
}
