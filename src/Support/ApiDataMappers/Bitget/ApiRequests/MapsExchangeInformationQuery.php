<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Bitget\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Support\ValueObjects\ApiProperties;

trait MapsExchangeInformationQuery
{
    public function prepareQueryMarketDataProperties(ApiSystem $apiSystem): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $apiSystem);

        // BitGet V2 requires productType for futures
        $properties->set('options.productType', 'USDT-FUTURES');

        return $properties;
    }

    /**
     * Resolves BitGet contracts response.
     *
     * BitGet V2 response structure:
     * {
     *     "code": "00000",
     *     "msg": "success",
     *     "requestTime": 1627116936176,
     *     "data": [
     *         {
     *             "symbol": "BTCUSDT",
     *             "baseCoin": "BTC",
     *             "quoteCoin": "USDT",
     *             "buyLimitPriceRatio": "0.01",
     *             "sellLimitPriceRatio": "0.01",
     *             "feeRateUpRatio": "0.005",
     *             "makerFeeRate": "0.0002",
     *             "takerFeeRate": "0.0006",
     *             "openCostUpRatio": "0.01",
     *             "supportMarginCoins": ["USDT"],
     *             "minTradeNum": "0.001",
     *             "priceEndStep": "1",
     *             "volumePlace": "3",
     *             "pricePlace": "1",
     *             "sizeMultiplier": "0.001",
     *             "symbolType": "perpetual",
     *             "minTradeUSDT": "5",
     *             "maxSymbolOrderNum": "200",
     *             "maxProductOrderNum": "500",
     *             "maxPositionNum": "150",
     *             "symbolStatus": "normal",
     *             "offTime": "-1",
     *             "limitOpenTime": "-1",
     *             "deliveryTime": "",
     *             "deliveryStartTime": "",
     *             "deliveryPeriod": ""
     *         }
     *     ]
     * }
     */
    public function resolveQueryMarketDataResponse(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), associative: true);

        $contracts = $data['data'] ?? [];

        // Stablecoins to exclude - these don't need price tracking as they're pegged to fiat
        $stablecoins = ['USDC', 'USDT', 'USDE', 'DAI', 'TUSD', 'BUSD', 'FRAX', 'USDP', 'GUSD', 'PAX', 'LUSD', 'SUSD', 'FDUSD', 'PYUSD', 'RLUSD', 'CUSD', 'USDD', 'USDJ', 'USTC', 'EURC', 'EURT'];

        $filtered = collect($contracts)
            // Only include normal/tradeable contracts
            ->filter(static function ($contract) {
                return ($contract['symbolStatus'] ?? '') === 'normal';
            })
            // Only include perpetual contracts
            ->filter(static function ($contract) {
                return ($contract['symbolType'] ?? '') === 'perpetual';
            })
            // Exclude stablecoins - they don't need price tracking
            ->filter(static function ($contract) use ($stablecoins) {
                $baseCoin = mb_strtoupper($contract['baseCoin'] ?? '');

                return ! in_array($baseCoin, $stablecoins, strict: true);
            });

        return $filtered
            ->map(static function ($contract) {
                $symbol = $contract['symbol'] ?? '';

                // BitGet provides pricePlace and volumePlace directly
                $pricePrecision = (int) ($contract['pricePlace'] ?? 2);
                $quantityPrecision = (int) ($contract['volumePlace'] ?? 3);

                // Calculate tick size from priceEndStep and pricePlace using bcmath
                // priceEndStep is the minimum increment in the last decimal digit
                // tickSize = priceEndStep / 10^pricePlace
                // Example: pricePlace=4, priceEndStep=1 → tickSize = 1 / 10000 = 0.0001
                $priceEndStep = (string) ($contract['priceEndStep'] ?? '1');
                $divisor = bcpow('10', (string) $pricePrecision, 0);
                $tickSize = bcdiv($priceEndStep, $divisor, $pricePrecision);

                return [
                    'pair' => $symbol,
                    'pricePrecision' => $pricePrecision,
                    'quantityPrecision' => $quantityPrecision,
                    'tickSize' => $tickSize,
                    'minPrice' => null,
                    'maxPrice' => null,
                    'minNotional' => isset($contract['minTradeUSDT']) ? (float) $contract['minTradeUSDT'] : null,

                    // Status and contract information
                    'status' => ($contract['symbolStatus'] ?? '') === 'normal' ? 'Trading' : 'Break',
                    'contractType' => $contract['symbolType'] ?? 'perpetual',
                    // deliveryTime empty means perpetual, otherwise it's the delisting date
                    'deliveryDate' => ! empty($contract['deliveryTime']) ? (int) $contract['deliveryTime'] : null,
                    'onboardDate' => null,
                    'baseAsset' => $contract['baseCoin'] ?? '',
                    'quoteAsset' => $contract['quoteCoin'] ?? '',
                    'marginAsset' => $contract['quoteCoin'] ?? '',
                ];
            })
            ->toArray();
    }
}
