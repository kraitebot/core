<?php

declare(strict_types=1);

namespace Kraite\Core\Concerns\Order;

use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Log;
use Kraite\Core\Support\Math;
use Kraite\Core\Support\Proxies\ApiDataMapperProxy;
use Kraite\Core\Support\ValueObjects\ApiProperties;
use Kraite\Core\Support\ValueObjects\ApiResponse;

trait InteractsWithApis
{
    public ApiProperties $apiProperties;

    public Response $apiResponse;

    public function apiAccount()
    {
        return $this->position->account;
    }

    public function apiMapper()
    {
        return new ApiDataMapperProxy($this->apiAccount()->apiSystem->canonical);
    }

    public function apiCancel(): ApiResponse
    {
        // Route to exchange-specific endpoints for stop/conditional orders
        if ($this->is_algo) {
            return match ($this->apiAccount()->apiSystem->canonical) {
                'binance' => $this->apiCancelAlgo(),
                'kucoin' => $this->apiCancelStopOrder(),
                'bitget' => $this->apiCancelPlanOrder(),
                default => $this->apiCancelDefault(), // Bybit uses same endpoint
            };
        }

        return $this->apiCancelDefault();
    }

    /**
     * Default cancel order implementation.
     */
    public function apiCancelDefault(): ApiResponse
    {
        $this->apiProperties = $this->apiMapper()->prepareOrderCancelProperties($this);
        $this->apiProperties->set('account', $this->apiAccount());
        $this->apiResponse = $this->apiAccount()->withApi()->cancelOrder($this->apiProperties);

        return new ApiResponse(
            response: $this->apiResponse,
            result: $this->apiMapper()->resolveOrderCancelResponse($this->apiResponse)
        );
    }

    /**
     * Cancel an algo order via Binance's Algo Order API.
     */
    public function apiCancelAlgo(): ApiResponse
    {
        $this->apiProperties = $this->apiMapper()->prepareAlgoOrderCancelProperties($this);
        $this->apiProperties->set('account', $this->apiAccount());
        $this->apiResponse = $this->apiAccount()->withApi()->cancelAlgoOrder($this->apiProperties);

        return new ApiResponse(
            response: $this->apiResponse,
            result: $this->apiMapper()->resolveAlgoOrderCancelResponse($this->apiResponse)
        );
    }

    /**
     * Modify an order's quantity and/or price on the exchange.
     *
     * Quantity and price flow as decimal strings end-to-end. Casting to
     * `?float` here would coerce any caller-provided string into a 64-bit
     * double, which PHP's default 14-digit float-to-string precision then
     * truncates before the mapper receives it — silent precision loss on
     * coarse-tick / low-price symbols and long-form qty values like
     * `58721234.123456789`. The mapper layer already does `(string) $value`
     * at the wire boundary, so accepting `string|int|float|null` here keeps
     * the value verbatim through to the request payload and aligns with
     * the engine's broader Math::* string-decimal discipline.
     *
     * @param  string|int|float|null  $quantity  Decimal quantity (preferred: string)
     * @param  string|int|float|null  $price  Decimal price (preferred: string)
     */
    public function apiModify(string|int|float|null $quantity = null, string|int|float|null $price = null): ApiResponse
    {
        if ($quantity === null || $quantity === '' || $quantity === 0 || $quantity === '0') {
            $quantity = $this->quantity;
        }

        if ($price === null || $price === '' || $price === 0 || $price === '0') {
            $price = $this->price;
        }

        $this->apiProperties = $this->apiMapper()->prepareOrderModifyProperties($this, $quantity, $price);
        $this->apiProperties->set('account', $this->apiAccount());
        $this->apiResponse = $this->apiAccount()->withApi()->modifyOrder($this->apiProperties);

        return new ApiResponse(
            response: $this->apiResponse,
            result: $this->apiMapper()->resolveOrderModifyResponse($this->apiResponse)
        );
    }

    /**
     * Query an order.
     */
    public function apiQuery(): ApiResponse
    {
        // Route to exchange-specific endpoints for stop/conditional orders
        if ($this->is_algo) {
            return match ($this->apiAccount()->apiSystem->canonical) {
                'binance' => $this->apiQueryAlgo(),
                'kucoin' => $this->apiQueryStopOrder(),
                'bitget' => $this->apiQueryPlanOrder(),
                default => $this->apiQueryDefault(), // Bybit uses same endpoint with orderFilter
            };
        }

        return $this->apiQueryDefault();
    }

    /**
     * Default order query implementation.
     */
    public function apiQueryDefault(): ApiResponse
    {
        $this->apiProperties = $this->apiMapper()->prepareOrderQueryProperties($this);
        $this->apiProperties->set('account', $this->apiAccount());
        $this->apiResponse = $this->apiAccount()->withApi()->orderQuery($this->apiProperties);

        return new ApiResponse(
            response: $this->apiResponse,
            result: $this->apiMapper()->resolveOrderQueryResponse($this->apiResponse)
        );
    }

    /**
     * Sync an order (query and update local record).
     */
    public function apiSync(): ApiResponse
    {
        // Route to exchange-specific sync for stop/conditional orders
        if ($this->is_algo) {
            return match ($this->apiAccount()->apiSystem->canonical) {
                'binance' => $this->apiSyncAlgo(),
                'kucoin' => $this->apiSyncStopOrder(),
                'bitget' => $this->apiSyncPlanOrder(),
                default => $this->apiSyncDefault(), // Bybit uses same endpoint
            };
        }

        return $this->apiSyncDefault();
    }

    /**
     * Default sync implementation.
     *
     * NOT_FOUND handling: Bybit and KuCoin return status = 'NOT_FOUND' when
     * an order is no longer in the active-orders list (typically because it
     * was filled or cancelled and the exchange moved it to history). Writing
     * that literal string to orders.status pollutes the DB — the observer
     * has no mapping for it and the order stays in limbo forever. Skip the
     * update and log instead; next sync will find a real status or a
     * history-aware sync method will pick it up.
     */
    public function apiSyncDefault(): ApiResponse
    {
        $apiResponse = $this->apiQueryDefault();

        if (($apiResponse->result['status'] ?? '') === 'NOT_FOUND') {
            Log::channel('jobs')->warning('[ORDER-SYNC] NOT_FOUND on sync — leaving DB status untouched', [
                'order_id' => $this->id,
                'db_status' => $this->status,
                'exchange_order_id' => $this->exchange_order_id,
            ]);

            return $apiResponse;
        }

        $incomingQuantity = $apiResponse->result['quantity']
            ?? $apiResponse->result['original_quantity']
            ?? null;

        $this->updateSaving([
            'status' => $apiResponse->result['status'],
            'quantity' => $this->resolveSyncedQuantity($incomingQuantity),
            'price' => $this->resolveSyncedPrice($apiResponse->result['price'] ?? null),
        ]);

        return $apiResponse;
    }

    /**
     * Place an order.
     */
    public function apiPlace(): ApiResponse
    {
        // Route to exchange-specific endpoints for stop/conditional orders
        if ($this->is_algo) {
            return match ($this->apiAccount()->apiSystem->canonical) {
                'binance' => $this->apiPlaceAlgo(),
                'kucoin' => $this->apiPlaceDefault(), // KuCoin uses same endpoint with stop params
                'bitget' => $this->apiPlaceTpslOrder(),
                default => $this->apiPlaceDefault(), // Bybit uses same endpoint with triggerPrice
            };
        }

        return $this->apiPlaceDefault();
    }

    /**
     * Default place order implementation.
     */
    public function apiPlaceDefault(): ApiResponse
    {
        $this->apiProperties = $this->apiMapper()->preparePlaceOrderProperties($this);
        $this->apiProperties->set('account', $this->apiAccount());
        $this->apiResponse = $this->apiAccount()->withApi()->placeOrder($this->apiProperties);

        $finalResponse = new ApiResponse(
            response: $this->apiResponse,
            result: $this->apiMapper()->resolvePlaceOrderResponse($this->apiResponse)
        );

        $this->updateSaving([
            'exchange_order_id' => $finalResponse->result['orderId'],
            'opened_at' => now(),
        ]);

        return $finalResponse;
    }

    /**
     * Place an algo order via Binance's Algo Order API.
     *
     * Since December 9, 2025, Binance migrated STOP_MARKET orders to this endpoint.
     * The response returns `algoId` instead of `orderId`.
     */
    public function apiPlaceAlgo(): ApiResponse
    {
        $this->apiProperties = $this->apiMapper()->preparePlaceAlgoOrderProperties($this);
        $this->apiProperties->set('account', $this->apiAccount());
        $this->apiResponse = $this->apiAccount()->withApi()->placeAlgoOrder($this->apiProperties);

        $finalResponse = new ApiResponse(
            response: $this->apiResponse,
            result: $this->apiMapper()->resolvePlaceAlgoOrderResponse($this->apiResponse)
        );

        $this->updateSaving([
            'exchange_order_id' => $finalResponse->result['orderId'],
            'opened_at' => now(),
        ]);

        return $finalResponse;
    }

    /**
     * Query an algo order via Binance's Algo Order API.
     */
    public function apiQueryAlgo(): ApiResponse
    {
        $this->apiProperties = $this->apiMapper()->prepareAlgoOrderQueryProperties($this);
        $this->apiProperties->set('account', $this->apiAccount());
        $this->apiResponse = $this->apiAccount()->withApi()->queryAlgoOrder($this->apiProperties);

        return new ApiResponse(
            response: $this->apiResponse,
            result: $this->apiMapper()->resolveAlgoOrderQueryResponse($this->apiResponse)
        );
    }

    /**
     * Sync an algo order (query and update local record).
     */
    public function apiSyncAlgo(): ApiResponse
    {
        $apiResponse = $this->apiQueryAlgo();

        $this->updateSaving([
            'status' => $apiResponse->result['status'],
            'quantity' => $this->resolveSyncedQuantity($apiResponse->result['quantity'] ?? null),
            'price' => $this->resolveSyncedPrice($apiResponse->result['price'] ?? null),
        ]);

        return $apiResponse;
    }

    // =========================================================================
    // KuCoin Stop Order Methods
    // =========================================================================

    /**
     * Query a stop order via KuCoin's Stop Orders API.
     */
    public function apiQueryStopOrder(): ApiResponse
    {
        $this->apiProperties = $this->apiMapper()->prepareStopOrderQueryProperties($this);
        $this->apiProperties->set('account', $this->apiAccount());
        $this->apiResponse = $this->apiAccount()->withApi()->getStopOrderDetail($this->apiProperties);

        return new ApiResponse(
            response: $this->apiResponse,
            result: $this->apiMapper()->resolveStopOrderQueryResponse($this->apiResponse)
        );
    }

    /**
     * Sync a stop order (query and update local record).
     */
    public function apiSyncStopOrder(): ApiResponse
    {
        $apiResponse = $this->apiQueryStopOrder();

        $this->updateSaving([
            'status' => $apiResponse->result['status'],
            'quantity' => $this->resolveSyncedQuantity($apiResponse->result['original_quantity'] ?? null),
            'price' => $this->resolveSyncedPrice($apiResponse->result['price'] ?? null),
        ]);

        return $apiResponse;
    }

    /**
     * Cancel a stop order via KuCoin's Stop Orders API.
     */
    public function apiCancelStopOrder(): ApiResponse
    {
        $this->apiProperties = $this->apiMapper()->prepareStopOrderCancelProperties($this);
        $this->apiProperties->set('account', $this->apiAccount());
        $this->apiResponse = $this->apiAccount()->withApi()->cancelStopOrder($this->apiProperties);

        return new ApiResponse(
            response: $this->apiResponse,
            result: $this->apiMapper()->resolveStopOrderCancelResponse($this->apiResponse)
        );
    }

    // =========================================================================
    // Bitget Plan Order Methods
    // =========================================================================

    /**
     * Place a plan order via Bitget's Plan Order API.
     */
    public function apiPlacePlanOrder(): ApiResponse
    {
        $this->apiProperties = $this->apiMapper()->preparePlacePlanOrderProperties($this);
        $this->apiProperties->set('account', $this->apiAccount());
        $this->apiResponse = $this->apiAccount()->withApi()->placePlanOrder($this->apiProperties);

        $finalResponse = new ApiResponse(
            response: $this->apiResponse,
            result: $this->apiMapper()->resolvePlacePlanOrderResponse($this->apiResponse)
        );

        $this->updateSaving([
            'exchange_order_id' => $finalResponse->result['orderId'],
            'opened_at' => now(),
        ]);

        return $finalResponse;
    }

    /**
     * Place a TP/SL order via Bitget's place-pos-tpsl endpoint.
     *
     * Uses place-pos-tpsl with only the relevant TP or SL parameters
     * to create position-level orders (not partial). After placement,
     * queries the position to fetch the new order ID.
     */
    public function apiPlaceTpslOrder(): ApiResponse
    {
        $this->apiProperties = $this->apiMapper()->preparePlaceTpslOrderProperties($this);
        $this->apiProperties->set('account', $this->apiAccount());
        $this->apiResponse = $this->apiAccount()->withApi()->placePosTpsl($this->apiProperties);

        $finalResponse = new ApiResponse(
            response: $this->apiResponse,
            result: $this->apiMapper()->resolvePlaceTpslOrderResponse($this->apiResponse)
        );

        if ($finalResponse->result['success'] ?? false) {
            $returnedClientOrderId = $finalResponse->result['clientOid'] ?? null;
            $orderId = $finalResponse->result['orderId'] ?? null;

            if (is_string($returnedClientOrderId)
                && $returnedClientOrderId !== ''
                && $returnedClientOrderId !== $this->client_order_id) {
                $orderId = null;
            }

            if (! is_string($orderId) || $orderId === '') {
                $orderId = $this->fetchTpslOrderIdFromPosition();
            }

            $this->updateSaving([
                'exchange_order_id' => $orderId,
                'opened_at' => now(),
            ]);

            $finalResponse->result['orderId'] = $orderId;
        }

        return $finalResponse;
    }

    /**
     * Query a plan order via Bitget's Plan Order API.
     */
    public function apiQueryPlanOrder(): ApiResponse
    {
        $this->apiProperties = $this->apiMapper()->preparePlanOrderQueryProperties($this);
        $this->apiProperties->set('account', $this->apiAccount());
        $this->apiResponse = $this->apiAccount()->withApi()->getPlanOrderDetail($this->apiProperties);

        return new ApiResponse(
            response: $this->apiResponse,
            result: $this->apiMapper()->resolvePlanOrderQueryResponse(
                $this->apiResponse,
                (string) $this->exchange_order_id
            )
        );
    }

    /**
     * Sync a plan order (query and update local record).
     *
     * For Bitget TPSL orders: First checks pending list, then history if not found.
     */
    public function apiSyncPlanOrder(): ApiResponse
    {
        $apiResponse = $this->apiQueryPlanOrder();

        // If not found in pending list, check history (order may be filled/cancelled)
        if (($apiResponse->result['status'] ?? '') === 'NOT_FOUND') {
            $apiResponse = $this->apiQueryPlanOrderHistory();
        }

        $this->updateSaving([
            'status' => $apiResponse->result['status'],
            'quantity' => $this->resolveSyncedQuantity($apiResponse->result['quantity'] ?? null),
            'price' => $this->resolveSyncedPrice($apiResponse->result['price'] ?? null),
        ]);

        return $apiResponse;
    }

    /**
     * Query plan order history (for filled/cancelled orders not in pending list).
     */
    public function apiQueryPlanOrderHistory(): ApiResponse
    {
        $this->apiProperties = $this->apiMapper()->preparePlanOrderQueryProperties($this);
        $this->apiProperties->set('account', $this->apiAccount());
        $this->apiResponse = $this->apiAccount()->withApi()->getPlanOrderHistory($this->apiProperties);

        return new ApiResponse(
            response: $this->apiResponse,
            result: $this->apiMapper()->resolvePlanOrderQueryResponse(
                $this->apiResponse,
                (string) $this->exchange_order_id
            )
        );
    }

    /**
     * Cancel a plan order via Bitget's Plan Order API.
     */
    public function apiCancelPlanOrder(): ApiResponse
    {
        $this->apiProperties = $this->apiMapper()->preparePlanOrderCancelProperties($this);
        $this->apiProperties->set('account', $this->apiAccount());
        $this->apiResponse = $this->apiAccount()->withApi()->cancelPlanOrder($this->apiProperties);

        return new ApiResponse(
            response: $this->apiResponse,
            result: $this->apiMapper()->resolvePlanOrderCancelResponse($this->apiResponse)
        );
    }

    /**
     * Modify TP/SL order trigger price via Bitget's position TP/SL API.
     *
     * Used when WAP changes and stop prices need recalculation.
     * Only applicable for Bitget orders placed via place-pos-tpsl endpoint.
     *
     * @param  string  $newTriggerPrice  The new trigger price for this TP or SL order
     */
    public function apiModifyTpsl(string $newTriggerPrice): ApiResponse
    {
        $this->apiProperties = $this->apiMapper()->prepareModifyTpslOrderProperties($this, $newTriggerPrice);
        $this->apiProperties->set('account', $this->apiAccount());
        $this->apiResponse = $this->apiAccount()->withApi()->modifyTpslOrder($this->apiProperties);

        $finalResponse = new ApiResponse(
            response: $this->apiResponse,
            result: $this->apiMapper()->resolveModifyTpslOrderResponse($this->apiResponse)
        );

        // Update local price on success
        if ($finalResponse->result['success'] ?? false) {
            $this->updateSaving([
                'price' => $newTriggerPrice,
            ]);
        }

        return $finalResponse;
    }

    /**
     * Resolve the price to write on sync, preserving the stored value when
     * the exchange returns null/empty/zero.
     *
     * Cancelled algo orders on some exchanges (notably Binance) echo back
     * `price=0` because the exchange no longer carries the trigger on a
     * cancelled record. Writing that 0 erases the original trigger price
     * from our DB and destroys the audit trail. Since no legitimately
     * synced order on our workflow ever resolves to a 0 price (LIMIT / TP
     * / SL all have a real price; MARKET is excluded from syncable), a
     * zero incoming value is always a "exchange-dropped" signal — keep
     * what we had.
     *
     * A positive incoming price is passed through `api_format_price` so the
     * stored value is tick-aligned against the symbol's current precision,
     * matching the contract enforced on the outbound (place/modify) path.
     */
    protected function resolveSyncedPrice(mixed $incoming): mixed
    {
        if (! Math::isPositive($incoming)) {
            return $this->price;
        }

        return api_format_price((string) $incoming, $this->position->exchangeSymbol);
    }

    /**
     * Normalize an incoming quantity before persisting it on sync. A null or
     * non-numeric payload keeps the DB value (sync responses occasionally
     * omit the field, e.g. KuCoin plan orders in certain states). A numeric
     * payload — including legitimate zero, such as Binance algo executedQty
     * for an unfilled STOP-MARKET with closePosition=true — is routed
     * through `api_format_quantity` so it lands on the symbol's lot grid,
     * matching the outbound placement contract.
     */
    protected function resolveSyncedQuantity(mixed $incoming): mixed
    {
        if ($incoming === null || ! is_numeric($incoming)) {
            return $this->quantity;
        }

        return api_format_quantity((string) $incoming, $this->position->exchangeSymbol);
    }

    /**
     * Fetch the TP or SL order ID from position query.
     *
     * Used after place-pos-tpsl which doesn't return the order ID directly.
     */
    private function fetchTpslOrderIdFromPosition(): ?string
    {
        $account = $this->apiAccount();
        $mapper = $this->apiMapper();

        $properties = $mapper->prepareQueryPositionsProperties($account);
        $properties->set('account', $account);

        $response = $account->withApi()->getPositions($properties);
        $positions = $mapper->resolveQueryPositionsResponse($response);

        // Find our position by symbol and direction
        $symbol = $this->position->exchangeSymbol->parsed_trading_pair;
        $direction = $account->isHedgeMode()
            ? mb_strtoupper($this->position->direction)
            : 'BOTH';
        $key = "{$symbol}:{$direction}";

        $positionData = $positions[$key] ?? [];

        // Return the relevant ID based on order type
        $isStopLoss = in_array(mb_strtoupper(str_replace('-', '_', $this->type)), ['STOP_MARKET', 'STOP_LOSS'], true);

        return $isStopLoss
            ? ($positionData['stopLossId'] ?? null)
            : ($positionData['takeProfitId'] ?? null);
    }
}
