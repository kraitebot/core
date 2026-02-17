<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Proxies;

use Exception;
use Kraite\Core\Support\Apis\REST\AlternativeMeApi;
use Kraite\Core\Support\Apis\REST\BinanceApi;
use Kraite\Core\Support\Apis\REST\BitgetApi;
use Kraite\Core\Support\Apis\REST\BybitApi;
use Kraite\Core\Support\Apis\REST\CoinmarketCapApi;
use Kraite\Core\Support\Apis\REST\KucoinApi;
use Kraite\Core\Support\Apis\REST\TaapiApi;
use Kraite\Core\Support\ValueObjects\ApiCredentials;

final class ApiRESTProxy
{
    private $api;

    public function __construct(string $apiType, ?ApiCredentials $credentials = null)
    {
        // Instantiate Kraite\Core\ropriate API class based on the API type
        switch ($apiType) {
            case 'binance':
                $this->api = new BinanceApi($credentials);
                break;
            case 'bybit':
                $this->api = new BybitApi($credentials);
                break;
            case 'kucoin':
                $this->api = new KucoinApi($credentials);
                break;
            case 'bitget':
                $this->api = new BitgetApi($credentials);
                break;
            case 'taapi':
                $this->api = new TaapiApi($credentials);
                break;
            case 'coinmarketcap':
                $this->api = new CoinmarketCapApi($credentials);
                break;
            case 'alternativeme':
                $this->api = new AlternativeMeApi;
                break;
            default:
                throw new Exception('Unsupported API: '.$apiType);
        }
    }

    /**
     * Magic method to dynamically call methods on the specific API class.
     */
    public function __call($method, $arguments)
    {
        // Check if the method exists on the instantiated API class
        if (method_exists($this->api, $method)) {
            return call_user_func_array([$this->api, $method], $arguments);
        }

        throw new Exception("Method {$method} does not exist for this API.");
    }
}
