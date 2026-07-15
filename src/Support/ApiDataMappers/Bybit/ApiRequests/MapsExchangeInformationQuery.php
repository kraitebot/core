<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Bybit\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Support\ValueObjects\ApiProperties;

trait MapsExchangeInformationQuery
{
    public function prepareQueryMarketDataProperties(ApiSystem $apiSystem): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $apiSystem);

        // Bybit requires category parameter for linear futures
        $properties->set('options.category', 'linear');

        // Set limit to max to get all symbols (Bybit default is 500, max is 1000)
        $properties->set('options.limit', 1000);

        return $properties;
    }

    public function resolveQueryMarketDataResponse(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), associative: true);

        // Bybit V5 API structure: {retCode, retMsg, result: {list: [...], nextPageCursor: ...}}
        $symbols = $data['result']['list'] ?? [];

        // Known crypto tickers to detect trading pairs like ETHBTC
        $majorCryptos = ['BTC', 'ETH', 'BNB', 'SOL', 'XRP', 'ADA', 'DOGE', 'AVAX', 'DOT', 'MATIC', 'SHIB', 'LTC', 'TRX', 'LINK'];

        // Stablecoins to exclude - these don't need price tracking as they're pegged to fiat
        $stablecoins = ['USDC', 'USDT', 'USDE', 'DAI', 'TUSD', 'BUSD', 'FRAX', 'USDP', 'GUSD', 'PAX', 'LUSD', 'SUSD', 'FDUSD', 'PYUSD', 'RLUSD', 'CUSD', 'USDD', 'USDJ', 'USTC', 'EURC', 'EURT'];

        return collect($symbols)
            // Only include perpetual contracts (exclude dated futures like BTCUSDT-31OCT25)
            ->filter(static function ($symbolData) {
                return ($symbolData['contractType'] ?? null) === 'LinearPerpetual';
            })
            ->map(static function ($symbolData) use ($majorCryptos, $stablecoins) {
                // Extract price filter
                $priceFilter = $symbolData['priceFilter'] ?? [];
                $lotSizeFilter = $symbolData['lotSizeFilter'] ?? [];
                $baseCoin = (string) ($symbolData['baseCoin'] ?? '');
                $isTradingPair = false;

                foreach ($majorCryptos as $crypto) {
                    if ($baseCoin !== $crypto
                        && mb_strlen($baseCoin) > mb_strlen($crypto)
                        && mb_strpos($baseCoin, $crypto) !== false) {
                        $isTradingPair = true;

                        break;
                    }
                }

                // Calculate price precision from priceScale
                $pricePrecision = (int) ($symbolData['priceScale'] ?? 2);

                // Calculate quantity precision from qtyStep
                $qtyStep = $lotSizeFilter['qtyStep'] ?? '0.001';
                $decimalPart = mb_strrchr($qtyStep, '.');
                $quantityPrecision = $decimalPart !== false ? mb_strlen(mb_substr($decimalPart, 1)) : 0;

                return [
                    // Map to canonical format matching Binance structure
                    'pair' => $symbolData['symbol'],
                    'pricePrecision' => $pricePrecision,
                    'quantityPrecision' => $quantityPrecision,
                    // Preserve raw exchange strings — DECIMAL columns
                    // and downstream consumers accept the string form
                    // unchanged, and skipping the float cast keeps full
                    // precision on exchanges that publish long-decimal
                    // tick / min-notional values.
                    'tickSize' => isset($priceFilter['tickSize']) ? (string) $priceFilter['tickSize'] : null,
                    'minPrice' => isset($priceFilter['minPrice']) ? (string) $priceFilter['minPrice'] : null,
                    'maxPrice' => isset($priceFilter['maxPrice']) ? (string) $priceFilter['maxPrice'] : null,
                    'minNotional' => isset($lotSizeFilter['minNotionalValue']) ? (string) $lotSizeFilter['minNotionalValue'] : null,

                    // Status and contract information
                    'status' => $symbolData['status'] ?? null,
                    'exchangeStatus' => $symbolData['status'] ?? null,
                    'isTrading' => ($symbolData['status'] ?? null) === 'Trading',
                    'isEligible' => mb_strpos((string) ($symbolData['symbol'] ?? ''), '_') === false
                        && ! $isTradingPair
                        && ! in_array(mb_strtoupper($baseCoin), $stablecoins, true),
                    'isDelisted' => ($symbolData['status'] ?? null) === 'Closed',
                    'contractType' => $symbolData['contractType'] ?? null,
                    // Bybit perpetuals have deliveryTime = "0" (string), which means no delivery
                    // Only return a value when it's a real timestamp (> 0)
                    'deliveryDate' => isset($symbolData['deliveryTime']) && (int) $symbolData['deliveryTime'] > 0
                        ? (int) $symbolData['deliveryTime']
                        : null,
                    'onboardDate' => isset($symbolData['launchTime']) ? (int) $symbolData['launchTime'] : 0,
                    'baseAsset' => $symbolData['baseCoin'] ?? null,
                    'quoteAsset' => $symbolData['quoteCoin'] ?? null,
                    'marginAsset' => $symbolData['settleCoin'] ?? null,
                ];
            })
            ->toArray();
    }
}
