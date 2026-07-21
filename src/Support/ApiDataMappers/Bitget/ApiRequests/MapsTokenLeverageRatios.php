<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Bitget\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\ApiDataMappers\Bitget\BitgetProductContext;
use Kraite\Core\Support\ValueObjects\ApiProperties;

trait MapsTokenLeverageRatios
{
    /**
     * Prepare properties for setting leverage on BitGet.
     *
     * BitGet requires: symbol, productType, marginCoin, leverage, holdSide (long/short).
     */
    public function prepareUpdateLeverageRatioProperties(Position $position, int $leverage): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $position);
        $properties->set('options.symbol', (string) $position->exchangeSymbol->asset);
        $context = BitgetProductContext::fromQuote($position->exchangeSymbol->quote);
        $properties->set('options.productType', $context->productType);
        $properties->set('options.marginCoin', $context->marginCoin);
        $properties->set('options.leverage', (string) $leverage);

        // holdSide is HEDGE-only — required to set leverage on the correct
        // LONG/SHORT slot independently. ONE-WAY mode shares leverage
        // across both directions on the same symbol; sending holdSide is
        // rejected. Omitting it tells Bitget to apply leverage to the
        // symbol globally.
        if ($position->account->isHedgeMode()) {
            $properties->set('options.holdSide', $this->directionType($position->direction));
        }

        return $properties;
    }

    /**
     * Resolves BitGet set leverage response.
     *
     * BitGet V2 response structure:
     * {
     *     "code": "00000",
     *     "msg": "success",
     *     "data": {
     *         "symbol": "BTCUSDT",
     *         "marginCoin": "USDT",
     *         "longLeverage": "20",
     *         "shortLeverage": "20"
     *     }
     * }
     */
    public function resolveUpdateLeverageRatioResponse(Response $response): array
    {
        $body = json_decode((string) $response->getBody(), associative: true);
        $data = $body['data'] ?? null;

        if (is_array($data)) {
            return $data;
        }

        return $data === null ? [] : ['result' => $data];
    }
}
