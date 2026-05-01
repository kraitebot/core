<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Recovery;

use Illuminate\Support\Str;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\ValueObjects\ApiProperties;
use Throwable;

/**
 * BybitPositionRecoverer
 *
 * Bybit V5 (Unified Trading) reports positions via /v5/position/list and
 * orders via /v5/order/realtime (live orders) and /v5/execution/list
 * (fill history). Stop orders share the realtime endpoint with the
 * `orderFilter=StopOrder` parameter — the BybitApi wrapper exposes both
 * via getCurrentOpenOrders and getStopOrders.
 *
 * UNTESTED — no live Bybit account exists for verification today
 * (account 2 is registered but inactive). Implementation mirrors the
 * Binance/Bitget recoverers structurally; per-field shape may need
 * adjustment when first exercised live.
 */
final class BybitPositionRecoverer extends AbstractPositionRecoverer
{
    protected function fetchOpenPositions(): array
    {
        $response = $this->account->apiQueryPositions();
        $positions = $response->result ?? [];

        $open = [];
        foreach ($positions as $p) {
            $size = (float) ($p['size'] ?? $p['positionAmt'] ?? 0);
            if ($size === 0.0) {
                continue;
            }

            $p['_openingPrice'] = (string) ($p['avgPrice'] ?? $p['breakEvenPrice'] ?? $p['markPrice'] ?? '0');
            $open[] = $p;
        }

        return $open;
    }

    protected function fetchOpenOrders(Position $position, array $exchangePosition): array
    {
        $symbol = (string) ($exchangePosition['symbol'] ?? $position->parsed_trading_pair);

        $regular = $this->fetchSymbolOrders($symbol, stop: false);
        $stop = $this->fetchSymbolOrders($symbol, stop: true);

        return [...$regular, ...$stop];
    }

    protected function fetchHistoricalFills(Position $position, array $exchangePosition): array
    {
        $symbol = (string) ($exchangePosition['symbol'] ?? $position->parsed_trading_pair);

        $properties = new ApiProperties;
        $properties->set('relatable', $position);
        $properties->set('account', $this->account);
        $properties->set('options.symbol', $symbol);
        $properties->set('options.category', 'linear');

        try {
            $response = $this->account->withApi()->getExecutionList($properties);
            $body = json_decode((string) $response->getBody(), associative: true);
        } catch (Throwable $e) {
            $this->report->warning("Bybit getExecutionList failed for {$symbol} on account #{$this->account->id}: {$e->getMessage()}");

            return [];
        }

        $list = $body['result']['list'] ?? [];
        if (! is_array($list) || $list === []) {
            return [];
        }

        return $this->bucketFillsByOrderId($list, $symbol);
    }

    protected function toLocalOrderAttributes(Position $position, array $exchangeOrder, bool $isFilled): array
    {
        $exchangeOrderId = (string) ($exchangeOrder['orderId']
            ?? $exchangeOrder['orderLinkId']
            ?? '');

        $isStop = ($exchangeOrder['_isStop'] ?? false)
            || ! empty($exchangeOrder['stopOrderType'])
            || ! empty($exchangeOrder['triggerPrice']);

        $type = $exchangeOrder['_localType'] ?? $this->mapOrderType($exchangeOrder, $isStop);
        $status = $exchangeOrder['_localStatus'] ?? $this->mapOrderStatus($exchangeOrder);

        $price = (string) ($exchangeOrder['price']
            ?? $exchangeOrder['triggerPrice']
            ?? $exchangeOrder['avgPrice']
            ?? '0');

        $quantity = (string) ($exchangeOrder['qty']
            ?? $exchangeOrder['execQty']
            ?? $exchangeOrder['cumExecQty']
            ?? '0');

        $rawTime = $exchangeOrder['createdTime']
            ?? $exchangeOrder['updatedTime']
            ?? $exchangeOrder['execTime']
            ?? null;

        $openedAt = null;
        if ($rawTime !== null) {
            $secs = (int) $rawTime;
            $secs = $secs > 9_999_999_999 ? intdiv($secs, 1000) : $secs;
            $openedAt = date('Y-m-d H:i:s', $secs);
        }

        $filledAt = $isFilled ? $openedAt : null;

        // Bybit positionIdx: 0 = one-way, 1 = LONG hedge, 2 = SHORT hedge.
        // We resolve to LONG/SHORT for local consistency.
        $positionSide = $position->direction;
        $positionIdx = $exchangeOrder['positionIdx'] ?? null;
        if ($positionIdx === 1 || $positionIdx === '1') {
            $positionSide = 'LONG';
        } elseif ($positionIdx === 2 || $positionIdx === '2') {
            $positionSide = 'SHORT';
        }

        return [
            'position_id' => $position->id,
            'exchange_order_id' => $exchangeOrderId,
            'client_order_id' => (string) ($exchangeOrder['orderLinkId'] ?? Str::uuid()->toString()),
            'type' => $type,
            'status' => $status,
            'side' => mb_strtoupper((string) ($exchangeOrder['side'] ?? 'BUY')),
            'position_side' => $positionSide,
            'is_algo' => $isStop,
            'price' => $price,
            'quantity' => $quantity,
            'reference_price' => $price,
            'reference_quantity' => $quantity,
            'reference_status' => $status,
            'opened_at' => $openedAt,
            'filled_at' => $filledAt,
        ];
    }

    protected function fetchSymbolOrders(string $symbol, bool $stop): array
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $this->account);
        $properties->set('account', $this->account);
        $properties->set('options.symbol', $symbol);
        $properties->set('options.category', 'linear');

        try {
            $response = $stop
                ? $this->account->withApi()->getStopOrders($properties)
                : $this->account->withApi()->getCurrentOpenOrders($properties);

            $body = json_decode((string) $response->getBody(), associative: true);
        } catch (Throwable $e) {
            $kind = $stop ? 'getStopOrders' : 'getCurrentOpenOrders';
            $this->report->warning("Bybit {$kind} failed for {$symbol} on account #{$this->account->id}: {$e->getMessage()}");

            return [];
        }

        $list = $body['result']['list'] ?? [];
        if (! is_array($list)) {
            return [];
        }

        return array_map(static function (array $o) use ($stop): array {
            $o['_isStop'] = $stop;

            return $o;
        }, $list);
    }

    protected function bucketFillsByOrderId(array $fills, string $symbol): array
    {
        $byOrder = [];
        foreach ($fills as $fill) {
            $orderId = (string) ($fill['orderId'] ?? '');
            if ($orderId === '') {
                continue;
            }

            $qty = (string) ($fill['execQty'] ?? '0');
            $price = (string) ($fill['execPrice'] ?? '0');
            $time = (int) ($fill['execTime'] ?? 0);

            if (! isset($byOrder[$orderId])) {
                $byOrder[$orderId] = [
                    'orderId' => $orderId,
                    'symbol' => $symbol,
                    'side' => mb_strtoupper((string) ($fill['side'] ?? '')),
                    'positionIdx' => $fill['positionIdx'] ?? null,
                    'qty_total' => '0',
                    'price_qty_sum' => '0',
                    'time_max' => 0,
                ];
            }

            $byOrder[$orderId]['qty_total'] = bcadd($byOrder[$orderId]['qty_total'], $qty, 8);
            $byOrder[$orderId]['price_qty_sum'] = bcadd(
                $byOrder[$orderId]['price_qty_sum'],
                bcmul($price, $qty, 8),
                8
            );
            $byOrder[$orderId]['time_max'] = max($byOrder[$orderId]['time_max'], $time);
        }

        $orders = [];
        foreach ($byOrder as $row) {
            $totalQty = $row['qty_total'];
            $avgPrice = (float) $totalQty > 0
                ? bcdiv($row['price_qty_sum'], $totalQty, 8)
                : '0';

            $orders[] = [
                'orderId' => $row['orderId'],
                'symbol' => $row['symbol'],
                'side' => $row['side'],
                'positionIdx' => $row['positionIdx'],
                'avgPrice' => $avgPrice,
                'qty' => $totalQty,
                'createdTime' => $row['time_max'],
                '_localType' => 'MARKET',
                '_localStatus' => 'FILLED',
            ];
        }

        return $orders;
    }

    protected function mapOrderType(array $exchangeOrder, bool $isStop): string
    {
        if ($isStop) {
            return 'STOP-MARKET';
        }

        $raw = mb_strtoupper((string) ($exchangeOrder['orderType'] ?? 'LIMIT'));

        return match ($raw) {
            'LIMIT' => 'LIMIT',
            'MARKET' => 'MARKET',
            default => $raw,
        };
    }

    protected function mapOrderStatus(array $exchangeOrder): string
    {
        $raw = mb_strtoupper((string) ($exchangeOrder['orderStatus'] ?? 'NEW'));

        return match ($raw) {
            'NEW', 'CREATED' => 'NEW',
            'PARTIALLYFILLED', 'PARTIALLY_FILLED' => 'PARTIALLY_FILLED',
            'FILLED' => 'FILLED',
            'CANCELLED', 'CANCELED', 'DEACTIVATED' => 'CANCELLED',
            default => $raw,
        };
    }
}
