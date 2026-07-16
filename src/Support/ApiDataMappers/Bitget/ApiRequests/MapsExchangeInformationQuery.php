<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Bitget\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Support\ApiDataMappers\Bitget\BitgetProductContext;
use Kraite\Core\Support\ValueObjects\ApiProperties;
use UnexpectedValueException;

trait MapsExchangeInformationQuery
{
    public function prepareQueryMarketDataProperties(ApiSystem $apiSystem, ?string $quote = null): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $apiSystem);
        $properties->set('options.productType', BitgetProductContext::fromQuote($quote)->productType);

        return $properties;
    }

    /**
     * Merge complete product catalogues before any mapping or persistence.
     *
     * @param  list<Response>  $responses
     */
    public function mergeQueryMarketDataResponses(array $responses): Response
    {
        $contracts = [];

        foreach ($responses as $response) {
            $payload = json_decode((string) $response->getBody(), associative: true);
            $productContracts = $payload['data'] ?? null;

            if (! is_array($productContracts)) {
                throw new UnexpectedValueException('Invalid Bitget futures catalogue response data.');
            }

            if ($productContracts === []) {
                throw new UnexpectedValueException('Bitget futures catalogue response is empty.');
            }

            array_push($contracts, ...$productContracts);
        }

        return new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode([
                'code' => '00000',
                'msg' => 'success',
                'requestTime' => now()->getTimestampMs(),
                'data' => $contracts,
            ], JSON_THROW_ON_ERROR)
        );
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
            // Only include perpetual contracts
            ->filter(static function ($contract) {
                return ($contract['symbolType'] ?? '') === 'perpetual';
            });

        return $filtered
            ->map(static function ($contract) use ($stablecoins) {
                $symbol = $contract['symbol'] ?? '';
                $status = mb_strtolower((string) ($contract['symbolStatus'] ?? ''));
                $offTime = (int) ($contract['offTime'] ?? 0);
                $deliveryTime = (int) ($contract['deliveryTime'] ?? 0);
                $launchTime = (int) ($contract['launchTime'] ?? 0);

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
                    'minNotional' => isset($contract['minTradeUSDT']) ? (string) $contract['minTradeUSDT'] : null,

                    // Status and contract information
                    'status' => $status === 'normal' ? 'Trading' : 'Break',
                    'exchangeStatus' => $contract['symbolStatus'] ?? null,
                    'isTrading' => $status === 'normal',
                    'isEligible' => ! in_array(mb_strtoupper((string) ($contract['baseCoin'] ?? '')), $stablecoins, true),
                    'isDelisted' => $status === 'off',
                    'contractType' => $contract['symbolType'] ?? 'perpetual',
                    // offTime is Bitget's removal timestamp; deliveryTime is a
                    // fallback for delivery contracts.
                    'deliveryDate' => $offTime > 0
                        ? $offTime
                        : ($deliveryTime > 0 ? $deliveryTime : null),
                    'onboardDate' => $launchTime > 0 ? $launchTime : null,
                    'baseAsset' => $contract['baseCoin'] ?? '',
                    'quoteAsset' => $contract['quoteCoin'] ?? '',
                    'marginAsset' => $contract['quoteCoin'] ?? '',
                ];
            })
            ->toArray();
    }
}
