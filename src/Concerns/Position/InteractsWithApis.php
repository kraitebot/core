<?php

declare(strict_types=1);

namespace Kraite\Core\Concerns\Position;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\Order;
use Kraite\Core\Support\ApiDataMappers\Bitget\BitgetProductContext;
use Kraite\Core\Support\Math;
use Kraite\Core\Support\Proxies\ApiDataMapperProxy;
use Kraite\Core\Support\ValueObjects\ApiProperties;
use Kraite\Core\Support\ValueObjects\ApiResponse;
use Throwable;

trait InteractsWithApis
{
    public ApiProperties $apiProperties;

    public Response $apiResponse;

    public function apiMapper()
    {
        return new ApiDataMapperProxy($this->account->apiSystem->canonical);
    }

    /**
     * Update margin type for the position's symbol on the exchange.
     *
     * The margin mode is read from the account's margin_mode setting.
     * Each exchange's ApiDataMapper handles the conversion to exchange-specific format.
     */
    public function apiUpdateMarginType(): ApiResponse
    {
        $this->apiProperties = $this->apiMapper()->prepareUpdateMarginTypeProperties($this);
        $this->apiProperties->set('account', $this->account);
        $this->apiResponse = $this->account->withApi()->updateMarginType($this->apiProperties);

        return new ApiResponse(
            response: $this->apiResponse,
            result: $this->apiMapper()->resolveUpdateMarginTypeResponse($this->apiResponse)
        );
    }

    // V4 ready.
    public function apiUpdateLeverageRatio(int $leverage): ApiResponse
    {
        $this->apiProperties = $this->apiMapper()->prepareUpdateLeverageRatioProperties($this, $leverage);
        $this->apiProperties->set('account', $this->account);
        $this->apiResponse = $this->account->withApi()->changeInitialLeverage($this->apiProperties);

        return new ApiResponse(
            response: $this->apiResponse,
            result: $this->apiMapper()->resolveUpdateLeverageRatioResponse($this->apiResponse)
        );
    }

    // V4 ready.
    public function apiCancelOpenOrders(): ApiResponse
    {
        $this->apiProperties = $this->apiMapper()->prepareCancelOrdersProperties($this);
        $this->apiProperties->set('account', $this->account);

        $this->apiResponse = $this->account->withApi()->cancelAllOpenOrders($this->apiProperties);

        return new ApiResponse(
            response: $this->apiResponse,
            result: $this->apiMapper()->resolveCancelOrdersResponse($this->apiResponse)
        );
    }

    /**
     * Query the exchange's user-trade history for this position's symbol.
     *
     * Scoped to the symbol only (no orderId filter) so a manual close on the
     * exchange — where our TP is CANCELLED/EXPIRED and therefore
     * `profitOrder()` returns null — still returns the closing fill. The
     * caller picks the correct trade from the returned list.
     */
    public function apiQueryTokenTrades()
    {
        $this->apiProperties = $this->apiMapper()->prepareQueryTokenTradesProperties($this);
        $this->apiProperties->set('account', $this->account);

        $this->apiResponse = $this->account->withApi()->accountTrades($this->apiProperties);

        return new ApiResponse(
            response: $this->apiResponse,
            result: $this->apiMapper()->resolveQueryTradeResponse($this->apiResponse)
        );
    }

    /**
     * Build the local-truth attributes for a MARKET-CANCEL close order.
     *
     * Returns null when no FILLED MARKET / LIMIT orders exist on the
     * position — there's nothing to close.
     *
     * Quantity is the SUM of every FILLED MARKET + LIMIT row, not just
     * the MARKET entry, so a LIMIT rung that filled mid-cancel-cascade
     * (rare but real on a fast price drop) is still flattened.
     *
     * `position_side` is always set from `direction`. The Binance/Bybit/
     * Kucoin place-order mappers branch on `account->isHedgeMode()` and
     * either inject positionSide (hedge) or set reduceOnly=true (one-way),
     * so the same Order row is correct in both modes.
     *
     * @return array{type:string, side:string, position_side:string, quantity:string, position_id:int}|null
     */
    public function buildCloseOrderAttributes(): ?array
    {
        $filledQuantity = (string) $this->orders()
            ->whereIn('type', ['MARKET', 'LIMIT'])
            ->where('status', 'FILLED')
            ->sum('quantity');

        if (! Math::gt($filledQuantity, '0')) {
            return null;
        }

        $direction = mb_strtoupper((string) $this->direction);
        $closeSide = $direction === 'LONG'
            ? $this->apiMapper()->sideType('SELL')
            : $this->apiMapper()->sideType('BUY');

        return [
            'type' => 'MARKET-CANCEL',
            'side' => $closeSide,
            'position_side' => $direction,
            'quantity' => $filledQuantity,
            'position_id' => $this->id,
        ];
    }

    /**
     * Close the position on the exchange via a reduceOnly MARKET-CANCEL
     * built from local DB truth.
     *
     * Bitget routes through its dedicated flash-close endpoint
     * (`apiCloseBitget`). Every other exchange shares this Binance-shaped
     * path. We do NOT pre-query the exchange's positions endpoint here —
     * the REST snapshot lags the WS trade ledger by 5-20s under load,
     * and a stale empty response previously caused this method to skip
     * the close silently and leave a residual on the exchange (Position
     * #577 TONUSDT, 2026-05-06). The local DB is updated by
     * `ProcessUserDataEventJob` from the user-data WS push, which lands
     * before the positions endpoint catches up.
     *
     * If the exchange truly has nothing to reduce (e.g. SL filled
     * microseconds before our close), Binance returns -2022 ReduceOnly
     * rejected and `ClosePositionAtomicallyJob` translates that into a
     * NonNotifiableException for operator reconcile.
     */
    public function apiClose(): ApiResponse
    {
        if ($this->account->apiSystem->canonical === 'bitget') {
            return $this->apiCloseBitget();
        }

        $attributes = $this->buildCloseOrderAttributes();

        if ($attributes === null) {
            return new ApiResponse;
        }

        $order = Order::create($attributes);

        try {
            $apiResponse = $order->apiPlace();
        } catch (Throwable $e) {
            // Speculative Order::create above already wrote a NEW row to
            // the local DB. apiPlace throws when the exchange rejects
            // (e.g. -2022 / 22002 "nothing to reduce" — the position has
            // already been flattened on the exchange). The caller maps
            // that rejection to `already_closed=true` success — but
            // without this cleanup the local row sits forever as a NEW
            // MARKET-CANCEL with no exchange_order_id, an orphan that
            // never syncs and clutters the orders table on every
            // TP-fill close path.
            $order->delete();

            throw $e;
        }

        $order->apiSync();
        $order->refresh();

        if ($order->price !== null && $order->status === 'FILLED') {
            $this->updateSaving(['closing_price' => $order->price]);
        }

        $order->updateSaving([
            'reference_price' => $order->price,
            'reference_quantity' => $order->quantity,
            'reference_status' => $order->status,
        ]);

        return $apiResponse;
    }

    /**
     * Close position using BitGet's flash close endpoint.
     *
     * BitGet has a dedicated endpoint for closing positions that's simpler
     * and more reliable than placing a market order with proper parameters.
     *
     * @see https://www.bitget.com/api-doc/contract/trade/Flash-Close-Position
     */
    public function apiCloseBitget(): ApiResponse
    {
        $context = BitgetProductContext::fromQuote($this->exchangeSymbol->quote);

        $this->apiProperties = new ApiProperties;
        $this->apiProperties->set('relatable', $this);
        $this->apiProperties->set('options.symbol', $this->exchangeSymbol->asset);
        $this->apiProperties->set('options.productType', $context->productType);
        $this->apiProperties->set('account', $this->account);

        // holdSide is HEDGE-only — required to disambiguate the LONG vs
        // SHORT slot when flash-closing. ONE-WAY has a single slot per
        // symbol; sending holdSide is rejected.
        if ($this->account->isHedgeMode()) {
            $this->apiProperties->set('options.holdSide', mb_strtolower($this->direction));
        }

        $this->apiResponse = $this->account->withApi()->flashClosePosition($this->apiProperties);

        $body = json_decode((string) $this->apiResponse->getBody(), associative: true);
        $successList = $body['data']['successList'] ?? [];

        // Query the flash close order to get the fill price for closing_price
        if (! empty($successList)) {
            $orderId = $successList[0]['orderId'] ?? null;
            if ($orderId) {
                $this->setClosingPriceFromBitgetOrder($orderId);
            }
        }

        return new ApiResponse(
            response: $this->apiResponse,
            result: [
                'success' => ($body['code'] ?? '') === '00000',
                'successList' => $successList,
                'failureList' => $body['data']['failureList'] ?? [],
            ]
        );
    }

    /**
     * Query a BitGet order and set the closing_price from its fill price.
     */
    private function setClosingPriceFromBitgetOrder(string $orderId): void
    {
        try {
            $context = BitgetProductContext::fromQuote($this->exchangeSymbol->quote);

            $queryProperties = new ApiProperties;
            $queryProperties->set('relatable', $this);
            $queryProperties->set('options.symbol', $this->exchangeSymbol->asset);
            $queryProperties->set('options.productType', $context->productType);
            $queryProperties->set('options.orderId', $orderId);
            $queryProperties->set('account', $this->account);

            $orderResponse = $this->account->withApi()->getOrderDetail($queryProperties);
            $orderBody = json_decode((string) $orderResponse->getBody(), associative: true);

            // BitGet returns priceAvg for filled market orders
            $fillPrice = $orderBody['data']['priceAvg'] ?? $orderBody['data']['price'] ?? null;

            if ($fillPrice !== null && $fillPrice !== '' && Math::gt((string) $fillPrice, '0')) {
                $this->updateSaving(['closing_price' => $fillPrice]);
            }
        } catch (Throwable $e) {
            // Log but don't fail - closing_price is nice to have
            info("Failed to get closing price for BitGet position {$this->id}: ".$e->getMessage());
        }
    }
}
