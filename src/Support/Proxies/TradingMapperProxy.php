<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Proxies;

use Exception;
use Kraite\Core\Support\TradingMappers\BinanceTradingMapper;
use Kraite\Core\Support\TradingMappers\BitgetTradingMapper;
use Kraite\Core\Support\TradingMappers\BybitTradingMapper;
use Kraite\Core\Support\TradingMappers\KucoinTradingMapper;

/**
 * TradingMapperProxy
 *
 * Factory proxy for exchange-specific trading logic.
 * Handles business rules that differ between exchanges (e.g., delisting detection).
 *
 * @method bool isNowDelisted(\Kraite\Core\Models\ExchangeSymbol $exchangeSymbol)
 */
final class TradingMapperProxy
{
    private $mapper;

    public function __construct(string $apiCanonical)
    {
        switch ($apiCanonical) {
            case 'binance':
                $this->mapper = new BinanceTradingMapper;
                break;
            case 'bybit':
                $this->mapper = new BybitTradingMapper;
                break;
            case 'kucoin':
                $this->mapper = new KucoinTradingMapper;
                break;
            case 'bitget':
                $this->mapper = new BitgetTradingMapper;
                break;
            default:
                throw new Exception('Unsupported Trading Mapper: '.$apiCanonical);
        }
    }

    /**
     * Magic method to dynamically call methods on the specific trading mapper.
     */
    public function __call($method, $arguments)
    {
        if (method_exists($this->mapper, $method)) {
            return call_user_func_array([$this->mapper, $method], $arguments);
        }

        throw new Exception("Method {$method} does not exist for this Trading Mapper.");
    }
}
