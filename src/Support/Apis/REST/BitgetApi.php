<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Apis\REST;

use InvalidArgumentException;
use Kraite\Core\Enums\BitgetAccountMode;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\ApiClients\REST\BitgetApiClient;
use Kraite\Core\Support\ApiDataMappers\Bitget\BitgetProductContext;
use Kraite\Core\Support\ValueObjects\ApiCredentials;
use Kraite\Core\Support\ValueObjects\ApiProperties;
use Kraite\Core\Support\ValueObjects\ApiRequest;

/**
 * BitgetApi
 *
 * High-level Bitget futures API.
 *
 * Public market data and Classic accounts use v2. Unified Trading Accounts
 * route private account, position, order, fill, and strategy operations to v3.
 *
 * @see https://www.bitget.com/api-doc/contract/intro
 * @see https://www.bitget.com/api-doc/uta/intro
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
        $this->requireProductContext($properties);

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
        $this->requireProductContext($properties);

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

        if ($this->isUnifiedAccount($properties)) {
            $this->swapProductTypeForCategory($properties);

            $apiRequest = ApiRequest::make(
                'GET',
                '/api/v3/position/current-position',
                $properties
            );

            return $this->client->signRequest($apiRequest);
        }

        $this->requireProductContext($properties);

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
        $properties ??= new ApiProperties;

        if ($this->isUnifiedAccount($properties)) {
            $this->prepareUnifiedAccountSettings($properties);

            return $this->client->signRequest(ApiRequest::make(
                'GET',
                '/api/v3/account/settings',
                $properties
            ));
        }

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

        if ($this->isUnifiedAccount($properties)) {
            // The v3 asset read is account-wide (all coins in one payload);
            // the futures productType filter does not apply. Requires the
            // key to carry the "UTA management (read)" scope.
            $properties->delete('options.productType');

            $apiRequest = ApiRequest::make(
                'GET',
                '/api/v3/account/assets',
                $properties
            );

            return $this->client->signRequest($apiRequest);
        }

        $this->requireProductContext($properties);

        $apiRequest = ApiRequest::make(
            'GET',
            '/api/v2/mix/account/accounts',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    /**
     * Get symbol-scoped account details, including the live position mode.
     *
     * @see https://www.bitget.com/api-doc/classic/contract/account/Get-Single-Account
     */
    public function getSingleAccount(?ApiProperties $properties = null): mixed
    {
        $properties ??= new ApiProperties;

        if ($this->isUnifiedAccount($properties)) {
            $this->prepareUnifiedAccountSettings($properties);

            return $this->client->signRequest(ApiRequest::make(
                'GET',
                '/api/v3/account/settings',
                $properties
            ));
        }

        $this->requireProductContext($properties);

        $apiRequest = ApiRequest::make(
            'GET',
            '/api/v2/mix/account/account',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    /**
     * Classic keys expose an `authorities` list on the v2 spot account
     * info; unified keys expose a `permissions` list on the v3 account
     * info. Both payloads feed ExchangeApiKeyPermissions, which branches
     * on the shape it receives.
     *
     * @see https://www.bitget.com/api-doc/classic/spot/account/Get-Account-Info
     * @see https://www.bitget.com/api-doc/uta/changelog (/api/v3/account/info)
     */
    public function getApiKeyPermissions(?ApiProperties $properties = null): mixed
    {
        $properties ??= new ApiProperties;

        if ($this->isUnifiedAccount($properties)) {
            $apiRequest = ApiRequest::make(
                'GET',
                '/api/v3/account/info',
                $properties
            );

            return $this->client->signRequest($apiRequest);
        }

        return $this->getClassicAccountInfo($properties);
    }

    /**
     * Raw v2 spot account info call, mode-lookup free. Doubles as the
     * account-mode detection probe: unified accounts reject it with error
     * 40085 before credential validation.
     */
    public function getClassicAccountInfo(?ApiProperties $properties = null): mixed
    {
        $properties ??= new ApiProperties;
        $apiRequest = ApiRequest::make(
            'GET',
            '/api/v2/spot/account/info',
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

        if ($this->isUnifiedAccount($properties)) {
            $this->swapProductTypeForCategory($properties);

            $apiRequest = ApiRequest::make(
                'GET',
                '/api/v3/trade/unfilled-orders',
                $properties
            );

            return $this->client->signRequest($apiRequest);
        }

        $this->requireProductContext($properties);

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

        if ($this->isUnifiedAccount($properties)) {
            $this->prepareUnifiedProductContext($properties);
            $properties->delete('options.planType');
            $properties->delete('options.type');
            $properties->delete('options.symbol');

            return $this->client->signRequest(ApiRequest::make(
                'GET',
                '/api/v3/trade/unfilled-strategy-orders',
                $properties
            ));
        }

        $this->requireProductContext($properties);

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

        if ($this->isUnifiedAccount($properties)) {
            $this->prepareUnifiedOrder($properties);

            return $this->client->signRequest(ApiRequest::make(
                'POST',
                '/api/v3/trade/place-order',
                $properties
            ));
        }

        $this->requireProductContext($properties);

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

        if ($this->isUnifiedAccount($properties)) {
            $this->prepareUnifiedOrderIdentity($properties, includeContext: false);

            return $this->client->signRequest(ApiRequest::make(
                'GET',
                '/api/v3/trade/order-info',
                $properties
            ));
        }

        $this->requireProductContext($properties);

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

        if ($this->isUnifiedAccount($properties)) {
            $this->prepareUnifiedOrderIdentity($properties);

            return $this->client->signRequest(ApiRequest::make(
                'POST',
                '/api/v3/trade/cancel-order',
                $properties
            ));
        }

        $this->requireProductContext($properties);

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

        if ($this->isUnifiedAccount($properties)) {
            $this->prepareUnifiedProductContext($properties);
            $this->renameOption($properties, 'newSize', 'qty');
            $this->renameOption($properties, 'newPrice', 'price');
            $properties->delete('options.marginCoin');
            $properties->delete('options.newClientOid');

            return $this->client->signRequest(ApiRequest::make(
                'POST',
                '/api/v3/trade/modify-order',
                $properties
            ));
        }

        $this->requireProductContext($properties);

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

        if ($this->isUnifiedAccount($properties)) {
            $this->prepareUnifiedProductContext($properties);
            $properties->delete('options.marginCoin');

            return $this->client->signRequest(ApiRequest::make(
                'POST',
                '/api/v3/trade/cancel-symbol-order',
                $properties
            ));
        }

        $this->requireProductContext($properties);

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

        if ($this->isUnifiedAccount($properties)) {
            $this->prepareUnifiedProductContext($properties);
            $properties->delete('options.symbol');

            return $this->client->signRequest(ApiRequest::make(
                'GET',
                '/api/v3/trade/fills',
                $properties
            ));
        }

        $this->requireProductContext($properties);

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
        $this->requireProductContext($properties);

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

        if ($this->isUnifiedAccount($properties)) {
            $this->prepareUnifiedProductContext($properties);
            $this->renameOption($properties, 'holdSide', 'posSide');
            $properties->delete('options.marginCoin');
            $account = $this->resolveAccount($properties);

            if ($account === null) {
                throw new InvalidArgumentException('Bitget UTA leverage changes require the related account.');
            }

            $marginMode = mb_strtolower((string) $account->margin_mode);
            $properties->set('options.marginMode', $marginMode);

            if ($marginMode === 'isolated' && ! $properties->has('options.posSide')) {
                $position = $properties->getOr('relatable', null);
                $direction = $position instanceof Position
                    ? mb_strtoupper((string) $position->direction)
                    : '';

                if (! in_array($direction, ['LONG', 'SHORT'], true)) {
                    throw new InvalidArgumentException(
                        'Bitget UTA isolated leverage changes require the related LONG or SHORT position side.'
                    );
                }

                $properties->set('options.posSide', mb_strtolower($direction));
            }

            return $this->client->signRequest(ApiRequest::make(
                'POST',
                '/api/v3/account/set-leverage',
                $properties
            ));
        }

        $this->requireProductContext($properties);

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

        if ($this->isUnifiedAccount($properties)) {
            throw new InvalidArgumentException(
                'Bitget UTA has no standalone per-symbol margin-mode endpoint. Margin mode must be applied by set-leverage and order placement.'
            );
        }

        $this->requireProductContext($properties);

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
        $this->requireProductContext($properties);

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

        if ($this->isUnifiedAccount($properties)) {
            $this->prepareUnifiedStrategyOrder($properties, triggerOrder: true);

            return $this->client->signRequest(ApiRequest::make(
                'POST',
                '/api/v3/trade/place-strategy-order',
                $properties
            ));
        }

        $this->requireProductContext($properties);

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

        if ($this->isUnifiedAccount($properties)) {
            $this->prepareUnifiedStrategyQuery($properties);

            return $this->client->signRequest(ApiRequest::make(
                'GET',
                '/api/v3/trade/unfilled-strategy-orders',
                $properties
            ));
        }

        $this->requireProductContext($properties);

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

        if ($this->isUnifiedAccount($properties)) {
            $this->prepareUnifiedStrategyQuery($properties);

            return $this->client->signRequest(ApiRequest::make(
                'GET',
                '/api/v3/trade/history-strategy-orders',
                $properties
            ));
        }

        $this->requireProductContext($properties);

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

        if ($this->isUnifiedAccount($properties)) {
            $this->prepareUnifiedStrategyIdentity($properties);

            return $this->client->signRequest(ApiRequest::make(
                'POST',
                '/api/v3/trade/cancel-strategy-order',
                $properties
            ));
        }

        $this->requireProductContext($properties);

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

        if ($this->isUnifiedAccount($properties)) {
            $this->prepareUnifiedStrategyOrder($properties);

            return $this->client->signRequest(ApiRequest::make(
                'POST',
                '/api/v3/trade/place-strategy-order',
                $properties
            ));
        }

        $this->requireProductContext($properties);

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

        if ($this->isUnifiedAccount($properties)) {
            $this->prepareUnifiedStrategyOrder($properties);

            return $this->client->signRequest(ApiRequest::make(
                'POST',
                '/api/v3/trade/place-strategy-order',
                $properties
            ));
        }

        $this->requireProductContext($properties);

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

        if ($this->isUnifiedAccount($properties)) {
            $this->prepareUnifiedStrategyModification($properties);

            return $this->client->signRequest(ApiRequest::make(
                'POST',
                '/api/v3/trade/modify-strategy-order',
                $properties
            ));
        }

        $this->requireProductContext($properties);

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
     * - productType: "USDT-FUTURES" or "USDC-FUTURES"
     * - holdSide: "long" or "short"
     *
     * @see https://www.bitget.com/api-doc/contract/trade/Flash-Close-Position
     */
    public function flashClosePosition(?ApiProperties $properties = null)
    {
        $properties ??= new ApiProperties;

        if ($this->isUnifiedAccount($properties)) {
            $this->prepareUnifiedProductContext($properties);
            $this->renameOption($properties, 'holdSide', 'posSide');

            return $this->client->signRequest(ApiRequest::make(
                'POST',
                '/api/v3/trade/close-positions',
                $properties
            ));
        }

        $this->requireProductContext($properties);

        $apiRequest = ApiRequest::make(
            'POST',
            '/api/v2/mix/order/close-positions',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    /**
     * Get historical (closed) positions with exchange-computed PnL.
     * Returns up to 3 months of data.
     */
    public function historyPosition(?ApiProperties $properties = null)
    {
        $properties ??= new ApiProperties;

        if ($this->isUnifiedAccount($properties)) {
            $this->prepareUnifiedProductContext($properties);

            return $this->client->signRequest(ApiRequest::make(
                'GET',
                '/api/v3/position/history-position',
                $properties
            ));
        }

        $this->requireProductContext($properties);

        $apiRequest = ApiRequest::make(
            'GET',
            '/api/v2/mix/position/history-position',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    /**
     * Whether the calling account runs in BitGet unified (UTA) mode.
     * Unified accounts must use the v3 API — classic v2 private calls
     * fail with error 40085. Mode is lazily detected and persisted by
     * the account model.
     */
    private function isUnifiedAccount(ApiProperties $properties): bool
    {
        $account = $properties->getOr('account', null) ?? $properties->getOr('relatable', null);

        return $account instanceof Account
            && $account->apiSystem?->canonical === 'bitget'
            && $account->resolveBitgetAccountMode() === BitgetAccountMode::Unified;
    }

    /**
     * v2 names the futures product filter `productType`; v3 names it
     * `category`. The values (USDT-FUTURES / COIN-FUTURES / USDC-FUTURES)
     * are identical across both generations.
     */
    private function swapProductTypeForCategory(ApiProperties $properties): void
    {
        $this->requireProductContext($properties);

        $properties->set('options.category', $properties->get('options.productType'));
        $properties->delete('options.productType');
    }

    private function prepareUnifiedProductContext(ApiProperties $properties): void
    {
        $this->swapProductTypeForCategory($properties);
        $properties->delete('options.marginCoin');
    }

    private function prepareUnifiedAccountSettings(ApiProperties $properties): void
    {
        foreach (['productType', 'marginCoin', 'symbol'] as $option) {
            $properties->delete("options.{$option}");
        }
    }

    private function prepareUnifiedOrder(ApiProperties $properties): void
    {
        $this->prepareUnifiedProductContext($properties);
        $this->renameOption($properties, 'size', 'qty');
        $this->renameOption($properties, 'force', 'timeInForce');

        $order = $properties->getOr('relatable', null);
        $account = $this->resolveAccount($properties);

        if (! $order instanceof Order || $account === null) {
            throw new InvalidArgumentException('Bitget UTA order placement requires the related order and account.');
        }

        $properties->set('options.side', mb_strtolower((string) $order->side));
        $isClosing = mb_strtolower((string) $properties->getOr('options.tradeSide', '')) === 'close'
            || mb_strtoupper((string) $properties->getOr('options.reduceOnly', '')) === 'YES'
            || (bool) ($order->reduce_only ?? false);

        $properties->delete('options.tradeSide');
        $properties->delete('options.reduceOnly');

        if ($account->isHedgeMode()) {
            $properties->set('options.posSide', mb_strtolower((string) $order->position->direction));
        }

        if ($isClosing) {
            $properties->set('options.reduceOnly', 'yes');
        }

        $this->normalizeUnifiedClientOid($properties, $order);
    }

    private function prepareUnifiedOrderIdentity(ApiProperties $properties, bool $includeContext = true): void
    {
        if ($includeContext) {
            $this->prepareUnifiedProductContext($properties);
        } else {
            foreach (['productType', 'marginCoin', 'category'] as $option) {
                $properties->delete("options.{$option}");
            }
        }

        $properties->delete('options.symbol');
    }

    private function prepareUnifiedStrategyOrder(ApiProperties $properties, bool $triggerOrder = false): void
    {
        $this->prepareUnifiedProductContext($properties);
        $order = $properties->getOr('relatable', null);
        $account = $this->resolveAccount($properties);

        if (! $order instanceof Order || $account === null) {
            throw new InvalidArgumentException('Bitget UTA strategy placement requires the related order and account.');
        }

        $isClosing = ! $triggerOrder
            || mb_strtolower((string) $properties->getOr('options.tradeSide', '')) === 'close'
            || mb_strtoupper((string) $properties->getOr('options.reduceOnly', '')) === 'YES'
            || (bool) ($order->reduce_only ?? false);
        $properties->set('options.side', mb_strtolower((string) $order->side));
        $properties->delete('options.reduceOnly');
        $properties->delete('options.marginMode');
        $properties->delete('options.planType');
        $properties->delete('options.tradeSide');
        $properties->delete('options.holdSide');

        if ($account->isHedgeMode()) {
            $properties->set('options.posSide', mb_strtolower((string) $order->position->direction));
        }

        if ($isClosing) {
            $properties->set('options.reduceOnly', 'yes');
        }

        if ($triggerOrder) {
            $properties->set('options.type', 'trigger');
            $this->renameOption($properties, 'size', 'qty');
            $properties->set('options.triggerBy', $this->unifiedTriggerType($properties->getOr('options.triggerType', 'market')));
            $properties->set('options.triggerOrderType', $properties->getOr('options.orderType', 'market'));
            $properties->delete('options.triggerType');
            $properties->delete('options.orderType');
            $this->normalizeUnifiedClientOid($properties, $order);

            return;
        }

        $properties->set('options.type', 'tpsl');
        $properties->set('options.tpslMode', 'full');
        $properties->delete('options.size');

        if ($properties->has('options.stopSurplusTriggerPrice')) {
            $this->renameOption($properties, 'stopSurplusTriggerPrice', 'takeProfit');
            $properties->set('options.tpTriggerBy', $this->unifiedTriggerType(
                $properties->getOr('options.stopSurplusTriggerType', 'market')
            ));
            $properties->set('options.tpOrderType', 'market');
            $this->renameOption($properties, 'stopSurplusClientOid', 'clientOid');
        }

        if ($properties->has('options.stopLossTriggerPrice')) {
            $this->renameOption($properties, 'stopLossTriggerPrice', 'stopLoss');
            $properties->set('options.slTriggerBy', $this->unifiedTriggerType(
                $properties->getOr('options.stopLossTriggerType', 'market')
            ));
            $properties->set('options.slOrderType', 'market');
            $this->renameOption($properties, 'stopLossClientOid', 'clientOid');
        }

        foreach ([
            'stopSurplusTriggerType',
            'stopLossTriggerType',
            'stopSurplusExecutePrice',
            'stopLossExecutePrice',
        ] as $option) {
            $properties->delete("options.{$option}");
        }

        $this->normalizeUnifiedClientOid($properties, $order);
    }

    private function prepareUnifiedStrategyQuery(ApiProperties $properties): void
    {
        $this->prepareUnifiedProductContext($properties);
        $properties->delete('options.symbol');
        $properties->delete('options.planType');
        $properties->delete('options.type');
    }

    private function prepareUnifiedStrategyIdentity(ApiProperties $properties): void
    {
        $orderIdList = $properties->getOr('options.orderIdList', []);
        $orderId = is_array($orderIdList) ? data_get($orderIdList, '0.orderId') : null;

        foreach (['productType', 'category', 'marginCoin', 'symbol', 'orderIdList'] as $option) {
            $properties->delete("options.{$option}");
        }

        if (! is_string($orderId) || $orderId === '') {
            throw new InvalidArgumentException('Bitget UTA strategy cancellation requires an order ID.');
        }

        $properties->set('options.orderId', $orderId);
    }

    private function prepareUnifiedStrategyModification(ApiProperties $properties): void
    {
        $order = $properties->getOr('relatable', null);

        if (! $order instanceof Order || ! is_string($order->exchange_order_id) || $order->exchange_order_id === '') {
            throw new InvalidArgumentException('Bitget UTA strategy modification requires the related exchange order ID.');
        }

        foreach (['productType', 'category', 'marginCoin', 'symbol', 'holdSide'] as $option) {
            $properties->delete("options.{$option}");
        }

        $properties->set('options.orderId', $order->exchange_order_id);
        $properties->set('options.qty', (string) ($properties->getOr('unifiedQuantity', null) ?? $order->quantity));
        $properties->delete('unifiedQuantity');

        if ($properties->has('options.stopSurplusTriggerPrice')) {
            $this->renameOption($properties, 'stopSurplusTriggerPrice', 'takeProfit');
            $properties->set('options.tpTriggerBy', 'mark');
            $properties->set('options.tpOrderType', 'market');
        }

        if ($properties->has('options.stopLossTriggerPrice')) {
            $this->renameOption($properties, 'stopLossTriggerPrice', 'stopLoss');
            $properties->set('options.slTriggerBy', 'mark');
            $properties->set('options.slOrderType', 'market');
        }

        foreach ([
            'stopSurplusTriggerType',
            'stopLossTriggerType',
            'stopSurplusExecutePrice',
            'stopLossExecutePrice',
        ] as $option) {
            $properties->delete("options.{$option}");
        }
    }

    private function renameOption(ApiProperties $properties, string $from, string $to): void
    {
        if (! $properties->has("options.{$from}")) {
            return;
        }

        $properties->set("options.{$to}", $properties->get("options.{$from}"));
        $properties->delete("options.{$from}");
    }

    private function unifiedTriggerType(mixed $triggerType): string
    {
        return str_contains(mb_strtolower((string) $triggerType), 'mark') ? 'mark' : 'market';
    }

    private function normalizeUnifiedClientOid(ApiProperties $properties, Order $order): void
    {
        $clientOid = $properties->getOr('options.clientOid', null);

        if (! is_string($clientOid) || $clientOid === '') {
            return;
        }

        $normalized = $this->formatUnifiedClientOid($clientOid);
        $properties->set('options.clientOid', $normalized);

        if ((string) $order->client_order_id === $normalized) {
            return;
        }

        $order->setAttribute('client_order_id', $normalized);

        if ($order->exists) {
            $order->saveQuietly();
        }
    }

    private function formatUnifiedClientOid(string $clientOid): string
    {
        if (preg_match('/^[.A-Za-z0-9_:\/-]{1,32}$/', $clientOid) === 1) {
            return $clientOid;
        }

        $compactUuid = str_replace('-', '', $clientOid);
        if (preg_match('/^[A-Fa-f0-9]{32}$/', $compactUuid) === 1) {
            return $compactUuid;
        }

        return 'kraite-'.mb_substr(hash('sha256', $clientOid), 0, 25);
    }

    private function resolveAccount(ApiProperties $properties): ?Account
    {
        $account = $properties->getOr('account', null);

        if ($account instanceof Account) {
            return $account;
        }

        $relatable = $properties->getOr('relatable', null);

        return match (true) {
            $relatable instanceof Account => $relatable,
            $relatable instanceof Position => $relatable->account,
            $relatable instanceof Order => $relatable->position->account,
            default => null,
        };
    }

    private function requireProductContext(ApiProperties $properties): void
    {
        $productType = $properties->get('options.productType');

        if (! is_string($productType) || mb_trim($productType) === '') {
            throw new InvalidArgumentException(
                'Bitget futures productType is required. Resolve it from the account or exchange symbol quote.'
            );
        }

        $supportedProductTypes = array_map(
            static fn (string $quote): string => BitgetProductContext::fromQuote($quote)->productType,
            BitgetProductContext::supportedQuotes()
        );

        if (! in_array($productType, $supportedProductTypes, true)) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported Bitget futures productType [%s]. Supported product types: %s.',
                $productType,
                implode(', ', $supportedProductTypes)
            ));
        }
    }
}
