<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Bitget\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Str;
use Kraite\Core\Models\Order;
use Kraite\Core\Support\ApiDataMappers\Bitget\BitgetProductContext;
use Kraite\Core\Support\Math;
use Kraite\Core\Support\ValueObjects\ApiProperties;

/**
 * Maps Bitget Plan Order operations (STOP-MARKET, TAKE-PROFIT conditional orders).
 *
 * Bitget uses a separate Plan Order API for conditional orders that execute
 * when a trigger price is reached. This is similar to Binance's Algo Order API.
 *
 * @see https://www.bitget.com/api-doc/contract/plan/Place-Plan-Order
 */
trait MapsPlacePlanOrder
{
    /**
     * Prepare properties for placing a plan order on Bitget.
     *
     * @see https://www.bitget.com/api-doc/contract/plan/Place-Plan-Order
     */
    public function preparePlacePlanOrderProperties(Order $order): ApiProperties
    {
        // Auto-generate client order id, if null.
        if (is_null($order->client_order_id)) {
            $order->updateSaving(['client_order_id' => Str::uuid()->toString()]);
        }

        $properties = new ApiProperties;
        $properties->set('relatable', $order);
        $properties->set('options.symbol', (string) $order->position->exchangeSymbol->asset);
        $context = BitgetProductContext::fromQuote($order->position->exchangeSymbol->quote);
        $properties->set('options.productType', $context->productType);
        $properties->set('options.marginMode', mb_strtolower((string) $order->position->account->margin_mode));
        $properties->set('options.marginCoin', $context->marginCoin);
        $isHedgeMode = $order->position->account->isHedgeMode();
        $side = $isHedgeMode
            ? $this->hedgePositionSide($order->position)
            : $this->sideType($order->side);
        $properties->set('options.side', $side);
        $properties->set('options.size', (string) api_format_quantity($order->quantity, $order->position->exchangeSymbol));
        $properties->set('options.clientOid', (string) $order->client_order_id);

        // Plan order specific parameters
        $properties->set('options.planType', 'normal_plan');
        $properties->set('options.triggerPrice', (string) api_format_price($order->price, $order->position->exchangeSymbol));
        $properties->set('options.triggerType', 'mark_price');
        $properties->set('options.orderType', 'market');

        // Position-mode-aware payload. HEDGE: tradeSide=open/close so Bitget
        // routes the trigger to the correct LONG/SHORT slot. ONE-WAY:
        // tradeSide is ignored by the API; reduceOnly=YES is what makes
        // close-intent triggers actually reduce (rather than reopen the
        // opposite side).
        if ($isHedgeMode) {
            $properties->set('options.tradeSide', $this->determinePlanTradeSide($order));
        } elseif ($this->isPlanClosingIntent($order)) {
            $properties->set('options.reduceOnly', 'YES');
        }

        return $properties;
    }

    /**
     * Resolve Bitget place plan order response.
     *
     * Bitget V2 response structure:
     * {
     *     "code": "00000",
     *     "msg": "success",
     *     "data": {
     *         "orderId": "1234567890",
     *         "clientOid": "xxx"
     *     }
     * }
     */
    public function resolvePlacePlanOrderResponse(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), associative: true);
        $orderData = $data['data'] ?? [];

        return [
            'orderId' => $orderData['orderId'] ?? null,
            'clientOrderId' => $orderData['clientOid'] ?? null,
            'status' => 'NEW',
            '_isPlanOrder' => true,
            '_raw' => $data,
        ];
    }

    /**
     * Prepare properties for querying a plan order on Bitget.
     *
     * Note: Bitget doesn't have a single plan order query endpoint.
     * We use the pending orders list and filter by orderId in the resolver.
     * The orderId is NOT a valid API parameter - it's filtered client-side.
     *
     * @see https://www.bitget.com/api-doc/contract/plan/Get-Plan-Order-List
     */
    public function preparePlanOrderQueryProperties(Order $order): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $order);
        $properties->set('options.symbol', (string) $order->position->exchangeSymbol->asset);
        $properties->set(
            'options.productType',
            BitgetProductContext::fromQuote($order->position->exchangeSymbol->quote)->productType
        );

        // Use profit_loss planType for TPSL orders (covers pos_profit, pos_loss, profit_plan, loss_plan)
        $properties->set('options.planType', 'profit_loss');

        return $properties;
    }

    /**
     * Resolve Bitget plan order query response.
     *
     * The response contains a list of pending plan orders.
     * We need to find the one matching our orderId.
     *
     * Bitget V2 response structure:
     * {
     *     "code": "00000",
     *     "data": {
     *         "entrustedList": [
     *             {
     *                 "orderId": "1234567890",
     *                 "planType": "normal_plan",
     *                 "planStatus": "live",
     *                 "triggerPrice": "45000",
     *                 "size": "0.001",
     *                 "side": "buy",
     *                 ...
     *             }
     *         ]
     *     }
     * }
     */
    public function resolvePlanOrderQueryResponse(
        Response $response,
        ?string $targetOrderId = null,
        ?string $targetOrderType = null,
    ): array {
        $data = json_decode((string) $response->getBody(), associative: true);
        $responseData = $data['data'] ?? [];
        $orders = is_array($responseData)
            ? ($responseData['entrustedList'] ?? $responseData['list'] ?? (array_is_list($responseData) ? $responseData : []))
            : [];

        // Find the order matching our orderId
        $order = null;
        if ($targetOrderId !== null) {
            foreach ($orders as $o) {
                if (($o['orderId'] ?? '') === $targetOrderId) {
                    $order = $o;
                    break;
                }
            }
        } elseif (count($orders) === 1) {
            $order = $orders[0];
        }

        if (empty($order)) {
            return [
                'order_id' => null,
                'status' => 'NOT_FOUND',
                '_isPlanOrder' => true,
                '_raw' => $data,
            ];
        }

        $snapshots = $this->normalizePlanOrderSnapshots($order);
        $order = $this->selectPlanOrderSnapshot($snapshots, $targetOrderType);

        $isUnified = array_key_exists('status', $order)
            || array_key_exists('takeProfit', $order)
            || array_key_exists('stopLoss', $order);
        $status = $this->normalizePlanOrderStatus($order['planStatus'] ?? $order['status'] ?? '');
        $hasStopLoss = Math::gt((string) ($order['stopLoss'] ?? '0'), '0');
        $hasTrigger = Math::gt((string) ($order['triggerPrice'] ?? '0'), '0');
        $planType = $order['planType'] ?? ($hasTrigger ? 'normal_plan' : ($hasStopLoss ? 'pos_loss' : 'pos_profit'));

        // Position-level TPSL orders (pos_profit, pos_loss) have size=0 because they
        // track the entire position dynamically. Return null for quantity so the
        // sync fallback uses the order's existing quantity, preventing false drift.
        $isPositionTpsl = in_array($planType, ['pos_profit', 'pos_loss'], true);
        $quantity = $isPositionTpsl ? null : (string) ($order['size'] ?? '0');

        return [
            'order_id' => $order['orderId'] ?? null,
            'symbol' => $this->identifyBaseAndQuote($order['symbol'] ?? ''),
            'status' => $status,
            'price' => $this->computePlanOrderDisplayPrice($order),
            '_price' => $this->computePlanOrderDisplayPrice($order),
            'quantity' => $isPositionTpsl ? null : ($isUnified ? (string) ($order['qty'] ?? '0') : $quantity),
            'type' => $hasTrigger || $hasStopLoss || ! $isUnified ? 'STOP_MARKET' : 'PROFIT_LIMIT',
            '_orderType' => $hasTrigger || $hasStopLoss || ! $isUnified ? 'STOP_MARKET' : 'PROFIT_LIMIT',
            'side' => $order['side'] ?? '',
            '_isPlanOrder' => true,
            '_isPositionTpsl' => $isPositionTpsl,
            '_raw' => $order,
        ];
    }

    /**
     * Prepare properties for canceling a plan order on Bitget.
     *
     * @see https://www.bitget.com/api-doc/contract/plan/Cancel-Plan-Order
     */
    public function preparePlanOrderCancelProperties(Order $order): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $order);
        $properties->set('options.symbol', (string) $order->position->exchangeSymbol->asset);
        $context = BitgetProductContext::fromQuote($order->position->exchangeSymbol->quote);
        $properties->set('options.productType', $context->productType);
        $properties->set('options.marginCoin', $context->marginCoin);
        $properties->set('options.orderIdList', [[
            'orderId' => (string) $order->exchange_order_id,
            'clientOid' => '',
        ]]);

        return $properties;
    }

    /**
     * Resolve Bitget plan order cancel response.
     *
     * Bitget V2 response structure:
     * {
     *     "code": "00000",
     *     "msg": "success",
     *     "data": {
     *         "orderId": "1234567890",
     *         "clientOid": "xxx"
     *     }
     * }
     */
    public function resolvePlanOrderCancelResponse(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), associative: true);
        if (($data['code'] ?? '') === '00000' && ($data['data'] ?? null) === null) {
            return [
                'order_id' => null,
                'status' => 'CANCELLED',
                '_isPlanOrder' => true,
                '_raw' => $data,
            ];
        }

        $successList = $data['data']['successList'] ?? [];
        $cancelledOrder = is_array($successList) ? ($successList[0] ?? null) : null;
        $success = ($data['code'] ?? '') === '00000' && is_array($cancelledOrder);

        return [
            'order_id' => $cancelledOrder['orderId'] ?? null,
            'status' => $success ? 'CANCELLED' : 'NOT_FOUND',
            '_isPlanOrder' => true,
            '_raw' => $data,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $snapshots
     * @return array<string, mixed>
     */
    private function selectPlanOrderSnapshot(array $snapshots, ?string $targetOrderType): array
    {
        $targetPlanType = match ($targetOrderType) {
            'PROFIT-LIMIT', 'PROFIT-MARKET' => 'pos_profit',
            'STOP-MARKET', 'STOP-LIMIT' => 'pos_loss',
            default => null,
        };

        if ($targetPlanType !== null) {
            foreach ($snapshots as $snapshot) {
                if (($snapshot['planType'] ?? null) === $targetPlanType) {
                    return $snapshot;
                }
            }
        }

        return $snapshots[0] ?? [];
    }

    /**
     * Determine if this plan order is opening or closing a position.
     */
    private function determinePlanTradeSide(Order $order): string
    {
        return $this->isPlanClosingIntent($order) ? 'close' : 'open';
    }

    /**
     * Whether this plan order is closing intent — drives reduceOnly=YES
     * in one-way mode. Plan orders attached to existing positions (SL,
     * trailing TP) carry close intent.
     */
    private function isPlanClosingIntent(Order $order): bool
    {
        if ($order->reduce_only ?? false) {
            return true;
        }

        return in_array($order->type, [
            'PROFIT-LIMIT',
            'PROFIT-MARKET',
            'STOP-MARKET',
            'MARKET-CANCEL',
        ], strict: true);
    }

    /**
     * Compute the effective display price for plan orders.
     */
    private function computePlanOrderDisplayPrice(array $order): string
    {
        foreach (['triggerPrice', 'stopLoss', 'takeProfit'] as $field) {
            $triggerPrice = (string) ($order[$field] ?? '0');

            if (Math::gt($triggerPrice, 0)) {
                return $triggerPrice;
            }
        }

        return '0';
    }

    /**
     * Normalize Bitget plan order status to canonical format.
     *
     * Bitget plan statuses: live, not_trigger, executed, cancelled, fail
     */
    private function normalizePlanOrderStatus(string $status): string
    {
        return match (mb_strtolower($status)) {
            'live', 'not_trigger', 'pending', 'submitting' => 'NEW',
            'executed', 'triggered', 'success' => 'FILLED',
            'cancelled', 'canceled' => 'CANCELLED',
            'fail', 'failed' => 'REJECTED',
            default => mb_strtoupper($status),
        };
    }
}
