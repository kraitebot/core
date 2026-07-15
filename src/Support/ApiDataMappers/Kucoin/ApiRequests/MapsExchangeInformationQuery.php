<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Kucoin\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Support\ValueObjects\ApiProperties;

trait MapsExchangeInformationQuery
{
    public function prepareQueryMarketDataProperties(ApiSystem $apiSystem): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $apiSystem);

        return $properties;
    }

    /**
     * Resolves KuCoin contracts response.
     *
     * KuCoin Futures response structure:
     * {
     *     "code": "200000",
     *     "data": [
     *         {
     *             "symbol": "XBTUSDTM",
     *             "rootSymbol": "USDT",
     *             "type": "FFWCSX",
     *             "firstOpenDate": 1585555200000,
     *             "expireDate": null,
     *             "settleDate": null,
     *             "baseCurrency": "XBT",
     *             "quoteCurrency": "USDT",
     *             "settleCurrency": "USDT",
     *             "maxOrderQty": 1000000,
     *             "maxPrice": 1000000,
     *             "lotSize": 1,
     *             "tickSize": 1,
     *             "indexPriceTickSize": 0.01,
     *             "multiplier": 0.001,
     *             "initialMargin": 0.01,
     *             "maintainMargin": 0.005,
     *             "maxRiskLimit": 2000000,
     *             "minRiskLimit": 2000000,
     *             "riskStep": 1000000,
     *             "makerFeeRate": 0.0002,
     *             "takerFeeRate": 0.0006,
     *             "takerFixFee": 0,
     *             "makerFixFee": 0,
     *             "settlementFee": null,
     *             "isDeleverage": true,
     *             "isQuanto": true,
     *             "isInverse": false,
     *             "markMethod": "FairPrice",
     *             "fairMethod": "FundingRate",
     *             "fundingBaseSymbol": ".XBTINT8H",
     *             "fundingQuoteSymbol": ".USDTINT8H",
     *             "fundingRateSymbol": ".XBTUSDTMFPI8H",
     *             "indexSymbol": ".KXBTUSDT",
     *             "settlementSymbol": "",
     *             "status": "Open",
     *             "fundingFeeRate": 0.0001,
     *             "predictedFundingFeeRate": 0.0001,
     *             "openInterest": "27794882",
     *             "turnoverOf24h": 2352032,
     *             "volumeOf24h": 56789,
     *             "markPrice": 42000.5,
     *             "indexPrice": 42000,
     *             "lastTradePrice": 42001,
     *             "nextFundingRateTime": 25481000,
     *             "maxLeverage": 100,
     *             "sourceExchanges": ["huobi", "Okex", "Binance", "Kucoin", "Poloniex", "Hitbtc"],
     *             "premiumsSymbol1M": ".XBTUSDTMPI",
     *             "premiumsSymbol8H": ".XBTUSDTMPI8H",
     *             "fundingBaseSymbol1M": ".XBTINT",
     *             "fundingQuoteSymbol1M": ".USDTINT",
     *             "lowPrice": 41000,
     *             "highPrice": 43000,
     *             "priceChgPct": 0.02,
     *             "priceChg": 840
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
            // KuCoin perpetual contracts use the M suffix. Keeping lifecycle
            // rows with expireDate lets the sync record scheduled removals.
            ->filter(static function ($contract) {
                return str_ends_with((string) ($contract['symbol'] ?? ''), 'M');
            })
            // Only include USDT-margined contracts (not inverse)
            ->filter(static function ($contract) {
                return ($contract['isInverse'] ?? true) === false;
            });

        return $filtered
            ->map(function ($contract) use ($stablecoins) {
                $symbol = $contract['symbol'] ?? '';

                // Calculate precision from tickSize
                $tickSize = $contract['tickSize'] ?? 0.01;
                $pricePrecision = $this->calculatePrecisionFromStep((string) $tickSize);

                // Quantity precision from lotSize
                $lotSize = $contract['lotSize'] ?? 1;
                $quantityPrecision = $this->calculatePrecisionFromStep((string) $lotSize);

                return [
                    'pair' => $symbol,
                    'pricePrecision' => $pricePrecision,
                    'quantityPrecision' => $quantityPrecision,
                    // Preserve raw exchange strings — DECIMAL columns
                    // and downstream consumers accept the string form
                    // unchanged, and skipping the float cast keeps full
                    // precision on KuCoin's high-decimal lot multipliers.
                    'tickSize' => (string) $tickSize,
                    'minPrice' => null,
                    'maxPrice' => isset($contract['maxPrice']) ? (string) $contract['maxPrice'] : null,
                    'minNotional' => null,

                    // KuCoin-specific: lot size and multiplier for min order calculation
                    'kucoinLotSize' => isset($contract['lotSize']) ? (string) $contract['lotSize'] : null,
                    'kucoinMultiplier' => isset($contract['multiplier']) ? (string) $contract['multiplier'] : null,

                    // Status and contract information
                    'status' => ($contract['status'] ?? '') === 'Open' ? 'Trading' : 'Break',
                    'exchangeStatus' => $contract['status'] ?? null,
                    'isTrading' => ($contract['status'] ?? '') === 'Open',
                    'isEligible' => ! in_array(mb_strtoupper((string) ($contract['baseCurrency'] ?? '')), $stablecoins, true),
                    'isDelisted' => ($contract['status'] ?? '') === 'Closed',
                    'contractType' => $contract['type'] ?? null,
                    // expireDate null means perpetual, otherwise it's the delisting date
                    'deliveryDate' => $contract['expireDate'] ?? null,
                    'onboardDate' => $contract['firstOpenDate'] ?? 0,
                    'baseAsset' => $contract['baseCurrency'] ?? '',
                    'quoteAsset' => $contract['quoteCurrency'] ?? '',
                    'marginAsset' => $contract['settleCurrency'] ?? $contract['quoteCurrency'] ?? '',
                ];
            })
            ->toArray();
    }

    /**
     * Calculate decimal precision from a step value.
     */
    private function calculatePrecisionFromStep(string $step): int
    {
        $decimalPart = mb_strrchr($step, '.');

        if ($decimalPart === false) {
            return 0;
        }

        // Remove trailing zeros and count decimal places
        $trimmed = mb_rtrim(mb_substr($decimalPart, 1), '0');

        return mb_strlen($trimmed) > 0 ? mb_strlen($trimmed) : 0;
    }
}
