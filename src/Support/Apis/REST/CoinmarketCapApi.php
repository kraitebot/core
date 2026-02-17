<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Apis\REST;

use Kraite\Core\Support\ApiClients\REST\CoinmarketCapApiClient;
use Kraite\Core\Support\ValueObjects\ApiCredentials;
use Kraite\Core\Support\ValueObjects\ApiProperties;
use Kraite\Core\Support\ValueObjects\ApiRequest;

final class CoinmarketCapApi
{
    private $client;

    // Initializes CoinMarketCap API client with credentials.
    public function __construct(ApiCredentials $credentials)
    {
        $this->client = new CoinmarketCapApiClient([
            'url' => config('kraite.api.url.coinmarketcap.rest'),
            'api_key' => $credentials->get('coinmarketcap_api_key'),
        ]);
    }

    // https://coinmarketcap.com/api/documentation/v1/#operation/getV2CryptocurrencyInfo
    public function getSymbolsMetadata(ApiProperties $properties)
    {
        $apiRequest = ApiRequest::make(
            'GET',
            '/v1/cryptocurrency/info?',
            $properties
        );

        return $this->client->publicRequest($apiRequest);
    }

    // https://coinmarketcap.com/api/documentation/v1/#operation/getV1CryptocurrencyMap
    public function getSymbols(ApiProperties $properties)
    {
        $apiRequest = ApiRequest::make(
            'GET',
            '/v1/cryptocurrency/map',
            $properties
        );

        return $this->client->publicRequest($apiRequest);
    }

    // https://coinmarketcap.com/api/documentation/v1/#operation/getV1CryptocurrencyMarketpairsLatest
    public function getMarketPairs(ApiProperties $properties)
    {
        $apiRequest = ApiRequest::make(
            'GET',
            '/v1/cryptocurrency/market-pairs/latest',
            $properties
        );

        return $this->client->publicRequest($apiRequest);
    }
}
