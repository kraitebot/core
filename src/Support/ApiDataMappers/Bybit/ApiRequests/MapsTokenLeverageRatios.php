<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Bybit\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\ValueObjects\ApiProperties;

trait MapsTokenLeverageRatios
{
    /**
     * Prepare properties for updating leverage on Bybit.
     *
     * Note: Bybit requires both buyLeverage and sellLeverage to be set.
     * For one-way mode, they must be equal.
     *
     * @see https://bybit-exchange.github.io/docs/v5/position/leverage
     */
    public function prepareUpdateLeverageRatioProperties(Position $position, int $leverage): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $position);
        $properties->set('options.category', 'linear');
        $properties->set('options.symbol', (string) $position->exchangeSymbol->parsed_trading_pair);
        $properties->set('options.buyLeverage', (string) $leverage);
        $properties->set('options.sellLeverage', (string) $leverage);

        return $properties;
    }

    /**
     * Resolve the update leverage response from Bybit.
     *
     * Bybit V5 response structure:
     * {
     *     "retCode": 0,
     *     "retMsg": "OK",
     *     "result": {},
     *     "time": 1672281607343
     * }
     */
    public function resolveUpdateLeverageRatioResponse(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), associative: true);

        return [
            'success' => ($data['retCode'] ?? -1) === 0,
            '_raw' => $data,
        ];
    }
}
