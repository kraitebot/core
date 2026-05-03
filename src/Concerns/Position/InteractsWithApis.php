<?php

declare(strict_types=1);

namespace Kraite\Core\Concerns\Position;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\Order;
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

    // V4 ready.
    public function apiClose(): ApiResponse
    {
        // BitGet uses a dedicated flash close endpoint
        if ($this->account->apiSystem->canonical === 'bitget') {
            return $this->apiCloseBitget();
        }

        $apiResponse = $this->account->apiQueryPositions();
        $positions = $apiResponse->result;
        $want = mb_strtoupper(mb_trim($this->parsed_trading_pair));

        $matching = collect($positions)->filter(static function ($p) use ($want) {
            if (! isset($p['symbol'], $p['positionSide'], $p['positionAmt'])) {
                return false;
            }
            if (mb_strtoupper(mb_trim($p['symbol'])) !== $want) {
                return false;
            }

            // Epsilon-style "non-zero" — keep float for the comparison
            // since 0.0001 is the operational dust threshold; upgrading
            // to BCMath here would require a config knob for the scale
            // and isn't the precision-critical path. Same shape kept
            // intentionally; the upstream value is normalised already.
            $amt = (string) $p['positionAmt'];
            $abs = Math::lt($amt, '0') ? Math::sub('0', $amt) : $amt;

            return Math::gt($abs, '0.0001');
        });

        $symbols = $matching->pluck('symbol')->unique()->values();
        if ($symbols->count() > 1) {
            return new ApiResponse;
        }

        foreach ($matching as $positionData) {
            $side = Math::lt((string) $positionData['positionAmt'], '0')
            ? $this->apiMapper()->sideType('BUY')
            : $this->apiMapper()->sideType('SELL');

            // BCMath-aware absolute value preserves precision on
            // long-decimal positionAmt values that float would truncate.
            $rawAmt = (string) $positionData['positionAmt'];
            $absAmt = Math::lt($rawAmt, '0') ? Math::sub('0', $rawAmt) : $rawAmt;

            $data = [
                'type' => 'MARKET-CANCEL',
                'side' => $side,
                'position_side' => $positionData['positionSide'],
                'quantity' => $absAmt,
                'position_id' => $this->id,
            ];

            $order = Order::create($data);
            $apiResponse = $order->apiPlace();

            // Sync to get FILLED status and fill price (market orders fill immediately)
            $order->apiSync();

            // Set closing_price from the fill price
            $order->refresh();
            if ($order->price !== null && $order->status === 'FILLED') {
                $this->updateSaving(['closing_price' => $order->price]);
            }

            // Set reference fields (snapshot of order state after first sync)
            $order->updateSaving([
                'reference_price' => $order->price,
                'reference_quantity' => $order->quantity,
                'reference_status' => $order->status,
            ]);
        }

        return $apiResponse ?? new ApiResponse;
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
        $this->apiProperties = new ApiProperties;
        $this->apiProperties->set('relatable', $this);
        $this->apiProperties->set('options.symbol', $this->parsed_trading_pair);
        $this->apiProperties->set('options.productType', 'USDT-FUTURES');
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
            $queryProperties = new ApiProperties;
            $queryProperties->set('relatable', $this);
            $queryProperties->set('options.symbol', $this->parsed_trading_pair);
            $queryProperties->set('options.productType', 'USDT-FUTURES');
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
