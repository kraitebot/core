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
            // Remove symbols with underscores in the name (same logic as Binance)
            ->filter(static function ($symbolData) {
                return mb_strpos($symbolData['symbol'], '_') === false;
            })
            // Only include perpetual contracts (exclude dated futures like BTCUSDT-31OCT25)
            ->filter(static function ($symbolData) {
                return ($symbolData['contractType'] ?? null) === 'LinearPerpetual';
            })
            // Only include actively trading symbols (Bybit uses "Trading" status)
            ->filter(static function ($symbolData) {
                return ($symbolData['status'] ?? null) === 'Trading';
            })
            // Exclude trading pairs (e.g., ETHBTC) - tokens that contain another crypto ticker
            ->filter(static function ($symbolData) use ($majorCryptos) {
                $baseCoin = $symbolData['baseCoin'] ?? '';

                // Check if baseCoin contains any major crypto ticker (indicating it's a trading pair)
                foreach ($majorCryptos as $crypto) {
                    // Skip if the baseCoin IS the crypto itself
                    if ($baseCoin === $crypto) {
                        continue;
                    }
                    // If baseCoin contains another crypto ticker, it's likely a trading pair
                    if (mb_strpos($baseCoin, $crypto) !== false && mb_strlen($baseCoin) > mb_strlen($crypto)) {
                        return false;
                    }
                }

                return true;
            })
            // Exclude stablecoins - they don't need price tracking
            ->filter(static function ($symbolData) use ($stablecoins) {
                $baseCoin = mb_strtoupper($symbolData['baseCoin'] ?? '');

                return ! in_array($baseCoin, $stablecoins, strict: true);
            })
            ->map(static function ($symbolData) {
                // Extract price filter
                $priceFilter = $symbolData['priceFilter'] ?? [];
                $lotSizeFilter = $symbolData['lotSizeFilter'] ?? [];

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
                    'tickSize' => isset($priceFilter['tickSize']) ? (float) $priceFilter['tickSize'] : null,
                    'minPrice' => isset($priceFilter['minPrice']) ? (float) $priceFilter['minPrice'] : null,
                    'maxPrice' => isset($priceFilter['maxPrice']) ? (float) $priceFilter['maxPrice'] : null,
                    'minNotional' => isset($lotSizeFilter['minNotionalValue']) ? (float) $lotSizeFilter['minNotionalValue'] : null,

                    // Status and contract information
                    'status' => $symbolData['status'] ?? null,
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
