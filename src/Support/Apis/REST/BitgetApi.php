<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Apis\REST;

use Kraite\Core\Support\ApiClients\REST\BitgetApiClient;
use Kraite\Core\Support\ValueObjects\ApiCredentials;
use Kraite\Core\Support\ValueObjects\ApiProperties;
use Kraite\Core\Support\ValueObjects\ApiRequest;

/**
 * BitgetApi
 *
 * High-level API class for BitGet Futures V2 endpoints.
 * Read-only methods for balance, positions, and open orders.
 *
 * @see https://www.bitget.com/api-doc/contract/intro
 */
final class BitgetApi
{
    // The REST api client.
    private BitgetApiClient $client;

    // Initializes BitGet API client with credentials.
    public function __construct(ApiCredentials $credentials)
    {
        $this->client = new BitgetApiClient([
            'url' => config('kraite.api.url.bitget.rest'),

            // All ApiCredentials keys need to arrive encrypted.
            'api_key' => $credentials->get('bitget_api_key'),
            'api_secret' => $credentials->get('bitget_api_secret'),
            'passphrase' => $credentials->get('bitget_passphrase'),
        ]);
    }

    /**
     * Get server time.
     *
     * @see https://www.bitget.com/api-doc/common/public/Get-Server-Time
     */
    public function serverTime()
    {
        $apiRequest = ApiRequest::make(
            'GET',
            '/api/v2/public/time'
        );

        return $this->client->publicRequest($apiRequest);
    }

    /**
     * Get tradeable contracts (exchange information).
     *
     * @see https://www.bitget.com/api-doc/contract/market/Get-All-Symbols-Contracts
     */
    public function getExchangeInformation(?ApiProperties $properties = null)
    {
        $properties ??= new ApiProperties;

        // Default to USDT-FUTURES for perpetuals
        if (! $properties->has('options.productType')) {
            $properties->set('options.productType', 'USDT-FUTURES');
        }

        $apiRequest = ApiRequest::make(
            'GET',
            '/api/v2/mix/market/contracts',
            $properties
        );

        return $this->client->publicRequest($apiRequest);
    }

    /**
     * Get candlestick/kline data for a symbol.
     *
     * @see https://www.bitget.com/api-doc/contract/market/Get-Candle-Data
     */
    public function getKlines(?ApiProperties $properties = null)
    {
        $properties ??= new ApiProperties;

        // Default to USDT-FUTURES for perpetuals
        if (! $properties->has('options.productType')) {
            $properties->set('options.productType', 'USDT-FUTURES');
        }

        $apiRequest = ApiRequest::make(
            'GET',
            '/api/v2/mix/market/candles',
            $properties
        );

        return $this->client->publicRequest($apiRequest);
    }

    /**
     * Get open positions.
     *
     * @see https://www.bitget.com/api-doc/contract/position/Get-All-Position
     */
    public function getPositions(?ApiProperties $properties = null)
    {
        $properties ??= new ApiProperties;

        // Default to USDT-FUTURES for perpetuals
        if (! $properties->has('options.productType')) {
            $properties->set('options.productType', 'USDT-FUTURES');
        }

        $apiRequest = ApiRequest::make(
            'GET',
            '/api/v2/mix/position/all-position',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    /**
     * BitGet returns per-symbol leverage and marginMode inline on the positions
     * payload, so we reuse the same endpoint and normalize downstream.
     */
    public function getSymbolConfig(?ApiProperties $properties = null)
    {
        return $this->getPositions($properties);
    }

    /**
     * Get account information (balance).
     *
     * @see https://www.bitget.com/api-doc/contract/account/Get-Account-List
     */
    public function getAccountBalance(?ApiProperties $properties = null)
    {
        $properties ??= new ApiProperties;

        // Default to USDT-FUTURES for perpetuals
        if (! $properties->has('options.productType')) {
            $properties->set('options.productType', 'USDT-FUTURES');
        }

        $apiRequest = ApiRequest::make(
            'GET',
            '/api/v2/mix/account/accounts',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    /**
     * Get current open orders.
     *
     * @see https://www.bitget.com/api-doc/contract/trade/Get-Orders-Pending
     */
    public function getCurrentOpenOrders(?ApiProperties $properties = null)
    {
        $properties ??= new ApiProperties;

        // Default to USDT-FUTURES for perpetuals
        if (! $properties->has('options.productType')) {
            $properties->set('options.productType', 'USDT-FUTURES');
        }

        $apiRequest = ApiRequest::make(
            'GET',
            '/api/v2/mix/order/orders-pending',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    /**
     * Get account information (alias for getAccountBalance).
     */
    public function account(?ApiProperties $properties = null)
    {
        return $this->getAccountBalance($properties);
    }

    /**
     * Get pending plan orders (stop-loss, take-profit, trigger orders).
     *
     * @see https://www.bitget.com/api-doc/contract/plan/Get-Plan-Order-List
     */
    public function getPlanOrders(?ApiProperties $properties = null)
    {
        $properties ??= new ApiProperties;

        // Default to USDT-FUTURES for perpetuals
        if (! $properties->has('options.productType')) {
            $properties->set('options.productType', 'USDT-FUTURES');
        }

        $apiRequest = ApiRequest::make(
            'GET',
            '/api/v2/mix/order/orders-plan-pending',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    /**
     * Place a new order.
     *
     * @see https://www.bitget.com/api-doc/contract/trade/Place-Order
     */
    public function placeOrder(?ApiProperties $properties = null)
    {
        $properties ??= new ApiProperties;

        $apiRequest = ApiRequest::make(
            'POST',
            '/api/v2/mix/order/place-order',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    /**
     * Get order detail.
     *
     * @see https://www.bitget.com/api-doc/contract/trade/Get-Order-Details
     */
    public function getOrderDetail(?ApiProperties $properties = null)
    {
        $properties ??= new ApiProperties;

        $apiRequest = ApiRequest::make(
            'GET',
            '/api/v2/mix/order/detail',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    /**
     * Query order (alias for getOrderDetail for API compatibility).
     */
    public function orderQuery(?ApiProperties $properties = null)
    {
        return $this->getOrderDetail($properties);
    }

    /**
     * Cancel a single order.
     *
     * @see https://www.bitget.com/api-doc/contract/trade/Cancel-Order
     */
    public function cancelOrder(?ApiProperties $properties = null)
    {
        $properties ??= new ApiProperties;

        $apiRequest = ApiRequest::make(
            'POST',
            '/api/v2/mix/order/cancel-order',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    /**
     * Modify an existing order.
     *
     * @see https://www.bitget.com/api-doc/contract/trade/Modify-Order
     */
    public function modifyOrder(?ApiProperties $properties = null)
    {
        $properties ??= new ApiProperties;

        $apiRequest = ApiRequest::make(
            'POST',
            '/api/v2/mix/order/modify-order',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    /**
     * Cancel all orders for a symbol or product type.
     *
     * @see https://www.bitget.com/api-doc/contract/trade/Cancel-All-Orders
     */
    public function cancelAllOrders(?ApiProperties $properties = null)
    {
        $properties ??= new ApiProperties;

        $apiRequest = ApiRequest::make(
            'POST',
            '/api/v2/mix/order/cancel-all-orders',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    /**
     * Alias for cancelAllOrders() to match Binance API naming convention.
     *
     * Used by ClosePositionJob workflow to cancel all open orders when closing a position.
     */
    public function cancelAllOpenOrders(?ApiProperties $properties = null)
    {
        return $this->cancelAllOrders($properties);
    }

    /**
     * Get account-level trade fills for a symbol — Bitget's order-fills
     * endpoint, exposed under the cross-exchange `accountTrades` name so
     * `Position::apiQueryTokenTrades()` resolves it the same way it does
     * for Binance.
     *
     * @see https://www.bitget.com/api-doc/contract/trade/Get-Order-Fills
     */
    public function accountTrades(?ApiProperties $properties = null)
    {
        $properties ??= new ApiProperties;

        // Default to USDT-FUTURES for perpetuals
        if (! $properties->has('options.productType')) {
            $properties->set('options.productType', 'USDT-FUTURES');
        }

        $apiRequest = ApiRequest::make(
            'GET',
            '/api/v2/mix/order/fills',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    /**
     * Get symbol price (mark price, index price, last price).
     *
     * @see https://www.bitget.com/api-doc/contract/market/Get-Symbol-Price
     */
    public function getSymbolPrice(?ApiProperties $properties = null)
    {
        $properties ??= new ApiProperties;

        // Default to USDT-FUTURES for perpetuals
        if (! $properties->has('options.productType')) {
            $properties->set('options.productType', 'USDT-FUTURES');
        }

        $apiRequest = ApiRequest::make(
            'GET',
            '/api/v2/mix/market/symbol-price',
            $properties
        );

        return $this->client->publicRequest($apiRequest);
    }

    /**
     * Get mark price for a symbol.
     *
     * Uses the symbol price endpoint which includes mark price.
     *
     * @see https://www.bitget.com/api-doc/contract/market/Get-Symbol-Price
     */
    public function getMarkPrice(?ApiProperties $properties = null)
    {
        // Mark price is included in the symbol price response
        return $this->getSymbolPrice($properties);
    }

    /**
     * Set leverage for a position.
     *
     * @see https://www.bitget.com/api-doc/contract/account/Change-Leverage
     */
    public function setLeverage(?ApiProperties $properties = null)
    {
        $properties ??= new ApiProperties;

        $apiRequest = ApiRequest::make(
            'POST',
            '/api/v2/mix/account/set-leverage',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    /**
     * Change initial leverage (alias for setLeverage for API compatibility).
     */
    public function changeInitialLeverage(?ApiProperties $properties = null)
    {
        return $this->setLeverage($properties);
    }

    /**
     * Set margin mode (crossed or isolated).
     *
     * @see https://www.bitget.com/api-doc/contract/account/Change-Margin-Mode
     */
    public function setMarginMode(?ApiProperties $properties = null)
    {
        $properties ??= new ApiProperties;

        $apiRequest = ApiRequest::make(
            'POST',
            '/api/v2/mix/account/set-margin-mode',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    /**
     * Update margin type (alias for setMarginMode for API consistency).
     */
    public function updateMarginType(?ApiProperties $properties = null)
    {
        return $this->setMarginMode($properties);
    }

    /**
     * Get position tier / leverage brackets for a symbol.
     *
     * @see https://www.bitget.com/api-doc/contract/position/Get-Query-Position-Lever
     */
    public function getLeverageBrackets(?ApiProperties $properties = null)
    {
        $properties ??= new ApiProperties;

        // Default to USDT-FUTURES for perpetuals
        if (! $properties->has('options.productType')) {
            $properties->set('options.productType', 'USDT-FUTURES');
        }

        $apiRequest = ApiRequest::make(
            'GET',
            '/api/v2/mix/market/query-position-lever',
            $properties
        );

        return $this->client->publicRequest($apiRequest);
    }

    /**
     * Place a plan order (conditional/stop order).
     *
     * @see https://www.bitget.com/api-doc/contract/plan/Place-Plan-Order
     */
    public function placePlanOrder(?ApiProperties $properties = null)
    {
        $properties ??= new ApiProperties;

        $apiRequest = ApiRequest::make(
            'POST',
            '/api/v2/mix/order/place-plan-order',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    /**
     * Get plan order detail.
     *
     * Note: Uses the pending list endpoint and filters by orderId.
     *
     * @see https://www.bitget.com/api-doc/contract/plan/Get-Plan-Order-List
     */
    public function getPlanOrderDetail(?ApiProperties $properties = null)
    {
        $properties ??= new ApiProperties;

        // Default to USDT-FUTURES for perpetuals
        if (! $properties->has('options.productType')) {
            $properties->set('options.productType', 'USDT-FUTURES');
        }

        $apiRequest = ApiRequest::make(
            'GET',
            '/api/v2/mix/order/orders-plan-pending',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    /**
     * Get plan order history (filled/cancelled TPSL and trigger orders).
     *
     * @see https://www.bitget.com/api-doc/contract/plan/Get-Plan-Order-History
     */
    public function getPlanOrderHistory(?ApiProperties $properties = null)
    {
        $properties ??= new ApiProperties;

        // Default to USDT-FUTURES for perpetuals
        if (! $properties->has('options.productType')) {
            $properties->set('options.productType', 'USDT-FUTURES');
        }

        $apiRequest = ApiRequest::make(
            'GET',
            '/api/v2/mix/order/orders-plan-history',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    /**
     * Cancel a plan order.
     *
     * @see https://www.bitget.com/api-doc/contract/plan/Cancel-Plan-Order
     */
    public function cancelPlanOrder(?ApiProperties $properties = null)
    {
        $properties ??= new ApiProperties;

        $apiRequest = ApiRequest::make(
            'POST',
            '/api/v2/mix/order/cancel-plan-order',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    /**
     * Place position TP/SL (take-profit and stop-loss attached to position).
     *
     * This endpoint attaches TP/SL orders directly to an existing position.
     * Unlike plan orders, no size parameter is needed - the TP/SL automatically
     * applies to the entire position and adjusts when position size changes.
     *
     * @see https://www.bitget.com/api-doc/contract/plan/Place-Pos-Tpsl-Order
     */
    public function placePosTpsl(?ApiProperties $properties = null)
    {
        $properties ??= new ApiProperties;

        $apiRequest = ApiRequest::make(
            'POST',
            '/api/v2/mix/order/place-pos-tpsl',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    /**
     * Place a TP/SL order (individual take-profit or stop-loss).
     *
     * Unlike place-pos-tpsl which sets both TP and SL atomically, this creates
     * individual orders. Uses planType profit_plan (TP) or loss_plan (SL) to
     * ensure orders appear in the TP/SL tab, not Trigger tab.
     *
     * Use this endpoint when recreating cancelled TP/SL orders.
     *
     * @see https://www.bitget.com/api-doc/contract/plan/Place-Tpsl-Order
     */
    public function placeTpslOrder(?ApiProperties $properties = null)
    {
        $properties ??= new ApiProperties;

        $apiRequest = ApiRequest::make(
            'POST',
            '/api/v2/mix/order/place-tpsl-order',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    /**
     * Modify position TP/SL order trigger price.
     *
     * Used to update the trigger price of an existing position TP/SL.
     * Useful when WAP changes and stop prices need recalculation.
     *
     * @see https://www.bitget.com/api-doc/contract/plan/Modify-Tpsl-Order
     */
    public function modifyTpslOrder(?ApiProperties $properties = null)
    {
        $properties ??= new ApiProperties;

        $apiRequest = ApiRequest::make(
            'POST',
            '/api/v2/mix/order/modify-tpsl-order',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    /**
     * Flash close a position.
     *
     * Closes an entire position at market price in a single API call.
     * Much simpler than placing a market order with proper parameters.
     *
     * Required options:
     * - symbol: Trading pair (e.g., "JUPUSDT")
     * - productType: "USDT-FUTURES"
     * - holdSide: "long" or "short"
     *
     * @see https://www.bitget.com/api-doc/contract/trade/Flash-Close-Position
     */
    public function flashClosePosition(?ApiProperties $properties = null)
    {
        $properties ??= new ApiProperties;

        $apiRequest = ApiRequest::make(
            'POST',
            '/api/v2/mix/order/close-positions',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }
}
