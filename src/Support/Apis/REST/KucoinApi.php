<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Apis\REST;

use Kraite\Core\Support\ApiClients\REST\KucoinApiClient;
use Kraite\Core\Support\ValueObjects\ApiCredentials;
use Kraite\Core\Support\ValueObjects\ApiProperties;
use Kraite\Core\Support\ValueObjects\ApiRequest;

/**
 * KucoinApi
 *
 * High-level API class for KuCoin Futures endpoints.
 * Read-only methods for balance, positions, and open orders.
 *
 * @see https://www.kucoin.com/docs/rest/futures-trading/market-data/get-open-contract-list
 */
final class KucoinApi
{
    // The REST api client.
    private KucoinApiClient $client;

    // Initializes KuCoin API client with credentials.
    public function __construct(ApiCredentials $credentials)
    {
        $this->client = new KucoinApiClient([
            'url' => config('kraite.api.url.kucoin.rest'),

            // All ApiCredentials keys need to arrive encrypted.
            'api_key' => $credentials->get('kucoin_api_key'),
            'api_secret' => $credentials->get('kucoin_api_secret'),
            'passphrase' => $credentials->get('kucoin_passphrase'),
        ]);
    }

    /**
     * Get server time.
     *
     * @see https://www.kucoin.com/docs/rest/futures-trading/market-data/get-server-time
     */
    public function serverTime()
    {
        $apiRequest = ApiRequest::make(
            'GET',
            '/api/v1/timestamp'
        );

        return $this->client->publicRequest($apiRequest);
    }

    /**
     * Get tradeable contracts (exchange information).
     *
     * @see https://www.kucoin.com/docs/rest/futures-trading/market-data/get-open-contract-list
     */
    public function getExchangeInformation(?ApiProperties $properties = null)
    {
        $properties ??= new ApiProperties;

        $apiRequest = ApiRequest::make(
            'GET',
            '/api/v1/contracts/active',
            $properties
        );

        return $this->client->publicRequest($apiRequest);
    }

    /**
     * Get candlestick/kline data for a symbol.
     *
     * @see https://www.kucoin.com/docs/rest/futures-trading/market-data/get-klines
     */
    public function getKlines(?ApiProperties $properties = null)
    {
        $properties ??= new ApiProperties;

        $apiRequest = ApiRequest::make(
            'GET',
            '/api/v1/kline/query',
            $properties
        );

        return $this->client->publicRequest($apiRequest);
    }

    /**
     * Get open positions.
     *
     * @see https://www.kucoin.com/docs/rest/futures-trading/positions/get-position-list
     */
    public function getPositions(?ApiProperties $properties = null)
    {
        $properties ??= new ApiProperties;

        $apiRequest = ApiRequest::make(
            'GET',
            '/api/v1/positions',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    /**
     * KuCoin exposes per-symbol realLeverage and crossMode inline on the
     * positions payload, so we reuse the same endpoint and normalize downstream.
     */
    public function getSymbolConfig(?ApiProperties $properties = null)
    {
        return $this->getPositions($properties);
    }

    /**
     * Get account overview (balance information).
     *
     * @see https://www.kucoin.com/docs/rest/futures-trading/account/get-account-overview
     */
    public function getAccountBalance(?ApiProperties $properties = null)
    {
        $properties ??= new ApiProperties;

        // KuCoin requires currency parameter (default USDT)
        if (! $properties->has('options.currency')) {
            $properties->set('options.currency', 'USDT');
        }

        $apiRequest = ApiRequest::make(
            'GET',
            '/api/v1/account-overview',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    /**
     * Get current open orders.
     *
     * @see https://www.kucoin.com/docs/rest/futures-trading/orders/get-order-list
     */
    public function getCurrentOpenOrders(?ApiProperties $properties = null)
    {
        $properties ??= new ApiProperties;

        // Filter for active orders only
        $properties->set('options.status', 'active');

        $apiRequest = ApiRequest::make(
            'GET',
            '/api/v1/orders',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    /**
     * Get untriggered stop orders (conditional orders).
     *
     * @see https://www.kucoin.com/docs/rest/futures-trading/orders/get-untriggered-stop-order-list
     */
    public function getStopOrders(?ApiProperties $properties = null)
    {
        $properties ??= new ApiProperties;

        $apiRequest = ApiRequest::make(
            'GET',
            '/api/v1/stopOrders',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    /**
     * Get account information (alias for getAccountBalance).
     *
     * @see https://www.kucoin.com/docs/rest/futures-trading/account/get-account-overview
     */
    public function account(?ApiProperties $properties = null)
    {
        return $this->getAccountBalance($properties);
    }

    /**
     * Get WebSocket token for public channels.
     * Required before connecting to WebSocket - KuCoin uses dynamic endpoints.
     *
     * @see https://www.kucoin.com/docs/rest/futures-trading/websocket/apply-connect-token
     */
    public function getPublicWebSocketToken()
    {
        $apiRequest = ApiRequest::make(
            'POST',
            '/api/v1/bullet-public'
        );

        return $this->client->publicRequest($apiRequest);
    }

    /**
     * Get WebSocket token for private channels.
     * Required before connecting to authenticated WebSocket streams.
     *
     * @see https://www.kucoin.com/docs/rest/futures-trading/websocket/apply-connect-token
     */
    public function getPrivateWebSocketToken()
    {
        $apiRequest = ApiRequest::make(
            'POST',
            '/api/v1/bullet-private'
        );

        return $this->client->signRequest($apiRequest);
    }

    /**
     * Place a new order.
     *
     * @see https://www.kucoin.com/docs/rest/futures-trading/orders/place-order
     */
    public function placeOrder(?ApiProperties $properties = null)
    {
        $properties ??= new ApiProperties;

        $apiRequest = ApiRequest::make(
            'POST',
            '/api/v1/orders',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    /**
     * Get order details by orderId.
     *
     * @see https://www.kucoin.com/docs/rest/futures-trading/orders/get-order-details-by-orderid-clientoid
     */
    public function getOrder(?ApiProperties $properties = null)
    {
        $properties ??= new ApiProperties;
        $orderId = $properties->get('options.orderId');

        $apiRequest = ApiRequest::make(
            'GET',
            "/api/v1/orders/{$orderId}",
            $properties
        );

        // Remove orderId from options since it's in the URL
        $properties->forget('options.orderId');

        return $this->client->signRequest($apiRequest);
    }

    /**
     * Cancel an order by orderId.
     *
     * @see https://www.kucoin.com/docs/rest/futures-trading/orders/cancel-order-by-orderid
     */
    public function cancelOrder(?ApiProperties $properties = null)
    {
        $properties ??= new ApiProperties;
        $orderId = $properties->get('options.orderId');

        $apiRequest = ApiRequest::make(
            'DELETE',
            "/api/v1/orders/{$orderId}",
            $properties
        );

        // Remove orderId from options since it's in the URL
        $properties->forget('options.orderId');

        return $this->client->signRequest($apiRequest);
    }

    /**
     * Cancel all open orders for a symbol.
     *
     * @see https://www.kucoin.com/docs/rest/futures-trading/orders/cancel-multiple-futures-limit-orders
     */
    public function cancelAllOrders(?ApiProperties $properties = null)
    {
        $properties ??= new ApiProperties;

        $apiRequest = ApiRequest::make(
            'DELETE',
            '/api/v1/orders',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    /**
     * Get filled orders (trades/fills).
     *
     * @see https://www.kucoin.com/docs/rest/futures-trading/fills/get-filled-list
     */
    public function getFills(?ApiProperties $properties = null)
    {
        $properties ??= new ApiProperties;

        $apiRequest = ApiRequest::make(
            'GET',
            '/api/v1/fills',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    /**
     * Get current mark price for a symbol.
     *
     * @see https://www.kucoin.com/docs/rest/futures-trading/market-data/get-current-mark-price
     */
    public function getMarkPrice(?ApiProperties $properties = null)
    {
        $properties ??= new ApiProperties;
        $symbol = $properties->get('options.symbol');

        $apiRequest = ApiRequest::make(
            'GET',
            "/api/v1/mark-price/{$symbol}/current",
            $properties
        );

        // Remove symbol from options since it's in the URL
        $properties->delete('options.symbol');

        return $this->client->publicRequest($apiRequest);
    }

    /**
     * Get risk limit level for a symbol.
     *
     * @see https://www.kucoin.com/docs/rest/futures-trading/risk-limit/get-futures-risk-limit-level
     */
    public function getRiskLimitLevel(?ApiProperties $properties = null)
    {
        $properties ??= new ApiProperties;
        $symbol = $properties->get('options.symbol');

        $apiRequest = ApiRequest::make(
            'GET',
            "/api/v1/contracts/risk-limit/{$symbol}",
            $properties
        );

        // Remove symbol from options since it's in the URL
        $properties->delete('options.symbol');

        return $this->client->publicRequest($apiRequest);
    }

    /**
     * Change cross margin leverage for a symbol.
     *
     * @see https://www.kucoin.com/docs/rest/futures-trading/positions/modify-cross-margin-leverage
     */
    public function changeCrossLeverage(?ApiProperties $properties = null)
    {
        $properties ??= new ApiProperties;

        $apiRequest = ApiRequest::make(
            'POST',
            '/api/v2/changeCrossUserLeverage',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    /**
     * Change margin mode for a symbol.
     *
     * @see https://www.kucoin.com/docs-new/rest/futures-trading/positions/switch-margin-mode
     */
    public function changeMarginMode(?ApiProperties $properties = null)
    {
        $properties ??= new ApiProperties;

        $apiRequest = ApiRequest::make(
            'POST',
            '/api/v2/position/changeMarginMode',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    /**
     * Get leverage brackets (wrapper for getRiskLimitLevel).
     * Unified method name for ApiSystem\InteractsWithApis.
     *
     * @see https://www.kucoin.com/docs/rest/futures-trading/risk-limit/get-futures-risk-limit-level
     */
    public function getLeverageBrackets(?ApiProperties $properties = null)
    {
        return $this->getRiskLimitLevel($properties);
    }

    /**
     * Get a single stop order by orderId.
     *
     * @see https://www.kucoin.com/docs/rest/futures-trading/orders/get-details-of-a-single-untriggered-stop-order
     */
    public function getStopOrderDetail(?ApiProperties $properties = null)
    {
        $properties ??= new ApiProperties;
        $orderId = $properties->get('options.orderId');

        $apiRequest = ApiRequest::make(
            'GET',
            "/api/v1/stopOrders/{$orderId}",
            $properties
        );

        // Remove orderId from options since it's in the URL
        $properties->forget('options.orderId');

        return $this->client->signRequest($apiRequest);
    }

    /**
     * Cancel a stop order by orderId.
     *
     * @see https://www.kucoin.com/docs/rest/futures-trading/orders/cancel-stop-orders
     */
    public function cancelStopOrder(?ApiProperties $properties = null)
    {
        $properties ??= new ApiProperties;
        $orderId = $properties->get('options.orderId');

        $apiRequest = ApiRequest::make(
            'DELETE',
            "/api/v1/stopOrders/{$orderId}",
            $properties
        );

        // Remove orderId from options since it's in the URL
        $properties->forget('options.orderId');

        return $this->client->signRequest($apiRequest);
    }
}
