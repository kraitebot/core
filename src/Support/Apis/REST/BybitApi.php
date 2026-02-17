<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Apis\REST;

use Kraite\Core\Support\ApiClients\REST\BybitApiClient;
use Kraite\Core\Support\ValueObjects\ApiCredentials;
use Kraite\Core\Support\ValueObjects\ApiProperties;
use Kraite\Core\Support\ValueObjects\ApiRequest;

final class BybitApi
{
    // The REST api client.
    private $client;

    // Initializes Bybit API client with credentials.
    public function __construct(ApiCredentials $credentials)
    {
        $this->client = new BybitApiClient([
            'url' => config('kraite.api.url.bybit.rest'),

            // All ApiCredentials keys need to arrive encrypted.
            'api_key' => $credentials->get('bybit_api_key'),

            // All ApiCredentials keys need to arrive encrypted.
            'api_secret' => $credentials->get('bybit_api_secret'),
        ]);
    }

    // https://bybit-exchange.github.io/docs/v5/market/time
    public function serverTime()
    {
        $apiRequest = ApiRequest::make(
            'GET',
            '/v5/market/time'
        );

        return $this->client->publicRequest($apiRequest);
    }

    // https://bybit-exchange.github.io/docs/v5/market/risk-limit
    public function getLeverageBrackets(?ApiProperties $properties = null)
    {
        $properties ??= new ApiProperties;

        // Bybit requires category parameter - default to linear (USDT perpetual)
        if (! $properties->get('options.category')) {
            $properties->set('options.category', 'linear');
        }

        $apiRequest = ApiRequest::make(
            'GET',
            '/v5/market/risk-limit',
            $properties
        );

        return $this->client->publicRequest($apiRequest);
    }

    // https://bybit-exchange.github.io/docs/v5/market/instrument
    public function getExchangeInformation(ApiProperties $properties)
    {
        $apiRequest = ApiRequest::make(
            'GET',
            '/v5/market/instruments-info',
            $properties
        );

        return $this->client->publicRequest($apiRequest);
    }

    /**
     * Get candlestick/kline data for a symbol.
     *
     * @see https://bybit-exchange.github.io/docs/v5/market/kline
     */
    public function getKlines(?ApiProperties $properties = null)
    {
        $properties ??= new ApiProperties;

        // Bybit requires category parameter - default to linear (USDT perpetual)
        if (! $properties->get('options.category')) {
            $properties->set('options.category', 'linear');
        }

        $apiRequest = ApiRequest::make(
            'GET',
            '/v5/market/kline',
            $properties
        );

        return $this->client->publicRequest($apiRequest);
    }

    // https://bybit-exchange.github.io/docs/v5/account/wallet-balance
    public function account(ApiProperties $properties)
    {
        $apiRequest = ApiRequest::make(
            'GET',
            '/v5/account/wallet-balance',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    // https://bybit-exchange.github.io/docs/v5/position/position-info
    public function getPositions(?ApiProperties $properties = null)
    {
        $properties ??= new ApiProperties;

        // Bybit requires category parameter - default to linear (USDT perpetual)
        if (! $properties->get('options.category')) {
            $properties->set('options.category', 'linear');
        }

        // Bybit requires settleCoin parameter for linear positions
        if (! $properties->get('options.settleCoin')) {
            $properties->set('options.settleCoin', 'USDT');
        }

        $apiRequest = ApiRequest::make(
            'GET',
            '/v5/position/list',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    // https://bybit-exchange.github.io/docs/v5/account/wallet-balance
    public function getAccountBalance(?ApiProperties $properties = null)
    {
        $properties ??= new ApiProperties;

        // Bybit requires accountType parameter - default to UNIFIED
        if (! $properties->get('options.accountType')) {
            $properties->set('options.accountType', 'UNIFIED');
        }

        $apiRequest = ApiRequest::make(
            'GET',
            '/v5/account/wallet-balance',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    // https://bybit-exchange.github.io/docs/v5/order/open-order
    public function getCurrentOpenOrders(?ApiProperties $properties = null)
    {
        $properties ??= new ApiProperties;

        // Bybit requires category parameter - default to linear (USDT perpetual)
        if (! $properties->get('options.category')) {
            $properties->set('options.category', 'linear');
        }

        $apiRequest = ApiRequest::make(
            'GET',
            '/v5/order/realtime',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    // https://bybit-exchange.github.io/docs/v5/order/open-order
    // Stop orders use the same endpoint with orderFilter=StopOrder parameter.
    public function getStopOrders(?ApiProperties $properties = null)
    {
        // Reuse getCurrentOpenOrders - properties should already include orderFilter=StopOrder
        return $this->getCurrentOpenOrders($properties);
    }

    /**
     * Place a new order.
     *
     * @see https://bybit-exchange.github.io/docs/v5/order/create-order
     */
    public function placeOrder(?ApiProperties $properties = null)
    {
        $properties ??= new ApiProperties;

        $apiRequest = ApiRequest::make(
            'POST',
            '/v5/order/create',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    /**
     * Get order details.
     *
     * @see https://bybit-exchange.github.io/docs/v5/order/open-order
     */
    public function getOrder(?ApiProperties $properties = null)
    {
        $properties ??= new ApiProperties;

        // Bybit requires category parameter - default to linear
        if (! $properties->get('options.category')) {
            $properties->set('options.category', 'linear');
        }

        $apiRequest = ApiRequest::make(
            'GET',
            '/v5/order/realtime',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    /**
     * Cancel a single order.
     *
     * @see https://bybit-exchange.github.io/docs/v5/order/cancel-order
     */
    public function cancelOrder(?ApiProperties $properties = null)
    {
        $properties ??= new ApiProperties;

        $apiRequest = ApiRequest::make(
            'POST',
            '/v5/order/cancel',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    /**
     * Cancel all orders for a symbol.
     *
     * @see https://bybit-exchange.github.io/docs/v5/order/cancel-all
     */
    public function cancelAllOrders(?ApiProperties $properties = null)
    {
        $properties ??= new ApiProperties;

        $apiRequest = ApiRequest::make(
            'POST',
            '/v5/order/cancel-all',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    /**
     * Amend/modify an existing order.
     *
     * @see https://bybit-exchange.github.io/docs/v5/order/amend-order
     */
    public function amendOrder(?ApiProperties $properties = null)
    {
        $properties ??= new ApiProperties;

        $apiRequest = ApiRequest::make(
            'POST',
            '/v5/order/amend',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    /**
     * Get trade/execution history.
     *
     * @see https://bybit-exchange.github.io/docs/v5/order/execution
     */
    public function getExecutionList(?ApiProperties $properties = null)
    {
        $properties ??= new ApiProperties;

        // Bybit requires category parameter - default to linear
        if (! $properties->get('options.category')) {
            $properties->set('options.category', 'linear');
        }

        $apiRequest = ApiRequest::make(
            'GET',
            '/v5/execution/list',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    /**
     * Get ticker information including mark price.
     *
     * @see https://bybit-exchange.github.io/docs/v5/market/tickers
     */
    public function getTickers(?ApiProperties $properties = null)
    {
        $properties ??= new ApiProperties;

        // Bybit requires category parameter - default to linear
        if (! $properties->get('options.category')) {
            $properties->set('options.category', 'linear');
        }

        $apiRequest = ApiRequest::make(
            'GET',
            '/v5/market/tickers',
            $properties
        );

        return $this->client->publicRequest($apiRequest);
    }

    /**
     * Get mark price for a symbol.
     *
     * Uses the tickers endpoint which includes mark price.
     *
     * @see https://bybit-exchange.github.io/docs/v5/market/tickers
     */
    public function getMarkPrice(?ApiProperties $properties = null)
    {
        // Mark price is included in the tickers response
        return $this->getTickers($properties);
    }

    /**
     * Set leverage for a symbol.
     *
     * @see https://bybit-exchange.github.io/docs/v5/position/leverage
     */
    public function setLeverage(?ApiProperties $properties = null)
    {
        $properties ??= new ApiProperties;

        $apiRequest = ApiRequest::make(
            'POST',
            '/v5/position/set-leverage',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    /**
     * Switch between cross and isolated margin mode.
     *
     * @see https://bybit-exchange.github.io/docs/v5/position/cross-isolate
     */
    public function switchMarginMode(?ApiProperties $properties = null)
    {
        $properties ??= new ApiProperties;

        $apiRequest = ApiRequest::make(
            'POST',
            '/v5/position/switch-isolated',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }
}
