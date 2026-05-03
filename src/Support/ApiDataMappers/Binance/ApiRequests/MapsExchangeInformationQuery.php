<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Binance\ApiRequests;

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

    public function resolveQueryMarketDataResponse(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), associative: true);

        // Stablecoins to exclude - these don't need price tracking as they're pegged to fiat
        $stablecoins = ['USDC', 'USDT', 'USDE', 'DAI', 'TUSD', 'BUSD', 'FRAX', 'USDP', 'GUSD', 'PAX', 'LUSD', 'SUSD', 'FDUSD', 'PYUSD', 'RLUSD', 'CUSD', 'USDD', 'USDJ', 'USTC', 'EURC', 'EURT'];

        return collect($data['symbols'] ?? [])
            // Remove symbols with underscores in the name.
            ->filter(static function ($symbolData) {
                return mb_strpos($symbolData['symbol'], '_') === false;
            })
            // Only include perpetual contracts (exclude quarterly and dated futures)
            ->filter(static function ($symbolData) {
                return ($symbolData['contractType'] ?? null) === 'PERPETUAL';
            })
            // Only include actively trading symbols (exclude PENDING_TRADING, BREAK, SETTLING, etc.)
            ->filter(static function ($symbolData) {
                return ($symbolData['status'] ?? null) === 'TRADING';
            })
            // Only include ASCII tokens (exclude Chinese/special character tokens)
            ->filter(static function ($symbolData) {
                $baseAsset = $symbolData['baseAsset'] ?? '';

                // Only allow alphanumeric ASCII characters in token names
                return preg_match('/^[A-Za-z0-9]+$/', $baseAsset) === 1;
            })
            // Exclude stablecoins - they don't need price tracking
            ->filter(static function ($symbolData) use ($stablecoins) {
                $baseAsset = mb_strtoupper($symbolData['baseAsset'] ?? '');

                return ! in_array($baseAsset, $stablecoins, strict: true);
            })
            ->map(static function ($symbolData) {
                $filters = collect($symbolData['filters'] ?? []);

                $priceFilter = $filters->firstWhere('filterType', 'PRICE_FILTER');
                $minNotionalFilter = $filters->firstWhere('filterType', 'MIN_NOTIONAL');

                return [
                    // Existing fields
                    'pair' => $symbolData['symbol'],
                    'pricePrecision' => $symbolData['pricePrecision'],
                    'quantityPrecision' => $symbolData['quantityPrecision'],
                    // Preserve raw exchange strings — DECIMAL columns
                    // and downstream consumers accept the string form
                    // unchanged, and skipping the float cast keeps
                    // full precision on long-decimal tick / min-notional
                    // values.
                    'tickSize' => isset($priceFilter['tickSize']) ? (string) $priceFilter['tickSize'] : null,
                    'minPrice' => isset($priceFilter['minPrice']) ? (string) $priceFilter['minPrice'] : null,
                    'maxPrice' => isset($priceFilter['maxPrice']) ? (string) $priceFilter['maxPrice'] : null,
                    'minNotional' => isset($minNotionalFilter['notional']) ? (string) $minNotionalFilter['notional'] : null,

                    // New fields needed for delisting/tradeability logic and metadata
                    'status' => $symbolData['status'] ?? null,     // e.g. TRADING, BREAK, SETTLING, DELIVERING, PENDING_TRADING
                    'contractType' => $symbolData['contractType'] ?? null,     // e.g. PERPETUAL, CURRENT_QUARTER, NEXT_QUARTER
                    // deliveryDate in ms epoch; perpetuals have default 4133404800000 (Dec 25, 2100)
                    'deliveryDate' => isset($symbolData['deliveryDate']) ? (int) $symbolData['deliveryDate'] : null,
                    'onboardDate' => isset($symbolData['onboardDate']) ? (int) $symbolData['onboardDate'] : 0, // ms epoch
                    'baseAsset' => $symbolData['baseAsset'] ?? null,
                    'quoteAsset' => $symbolData['quoteAsset'] ?? null,
                    'marginAsset' => $symbolData['marginAsset'] ?? null,
                ];
            })
            ->toArray();
    }
}
