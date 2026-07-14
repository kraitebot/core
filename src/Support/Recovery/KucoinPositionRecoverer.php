<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Recovery;

use Illuminate\Support\Str;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\Math;
use Kraite\Core\Support\ValueObjects\ApiProperties;
use Throwable;

/**
 * KucoinPositionRecoverer
 *
 * KuCoin Futures uses a contract model where every position is reported
 * via /api/v1/positions and orders are split between active orders
 * (/api/v1/orders) and stop orders (/api/v1/stopOrders). Fills come from
 * /api/v1/fills.
 *
 * UNTESTED — no live KuCoin account exists for verification today
 * (account 3 is registered but inactive). Implementation mirrors the
 * Binance/Bitget recoverers structurally; per-field shape may need
 * adjustment when first exercised live.
 */
final class KucoinPositionRecoverer extends AbstractPositionRecoverer
{
    // No live KuCoin account exists for verification yet — gate behind
    // --allow-untested-exchange (see AbstractPositionRecoverer::isUntested).
    protected bool $untested = true;

    protected function fetchOpenPositions(): array
    {
        $response = $this->account->apiQueryPositions();
        $positions = $response->result ?? [];

        $open = [];
        foreach ($positions as $p) {
            $size = (string) ($p['currentQty'] ?? $p['positionAmt'] ?? $p['size'] ?? '0');
            if (Math::equal($size, '0')) {
                continue;
            }

            // KuCoin's avgEntryPrice / breakEvenPrice both exist in
            // different response shapes — prefer breakEven for WAP parity.
            $p['_openingPrice'] = (string) ($p['breakEvenPrice'] ?? $p['avgEntryPrice'] ?? $p['markPrice'] ?? '0');
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

        try {
            $response = $this->account->withApi()->getFills($properties);
            $body = json_decode((string) $response->getBody(), associative: true);
        } catch (Throwable $e) {
            $this->report->warning("KuCoin getFills failed for {$symbol} on account #{$this->account->id}: {$e->getMessage()}");

            return [];
        }

        $list = $body['data']['items'] ?? $body['data'] ?? [];
        if (! is_array($list) || $list === []) {
            return [];
        }

        return $this->bucketFillsByOrderId($list, $symbol);
    }

    protected function toLocalOrderAttributes(Position $position, array $exchangeOrder, bool $isFilled): array
    {
        $exchangeOrderId = (string) ($exchangeOrder['orderId']
            ?? $exchangeOrder['id']
            ?? '');

        $isStop = ! empty($exchangeOrder['stop']) || ! empty($exchangeOrder['stopPrice']);

        $type = $exchangeOrder['_localType'] ?? $this->mapOrderType($exchangeOrder, $isStop);
        $status = $exchangeOrder['_localStatus'] ?? $this->mapOrderStatus($exchangeOrder);

        $price = (string) ($exchangeOrder['price']
            ?? $exchangeOrder['stopPrice']
            ?? '0');

        $quantity = (string) ($exchangeOrder['size']
            ?? $exchangeOrder['filledSize']
            ?? '0');

        $rawTime = $exchangeOrder['createdAt'] ?? $exchangeOrder['updatedAt'] ?? null;
        $openedAt = null;
        if ($rawTime !== null) {
            $secs = (int) $rawTime;
            $secs = $secs > 9_999_999_999 ? intdiv($secs, 1000) : $secs;
            $openedAt = date('Y-m-d H:i:s', $secs);
        }

        $filledAt = $isFilled ? $openedAt : null;

        return [
            'position_id' => $position->id,
            'exchange_order_id' => $exchangeOrderId,
            'client_order_id' => (string) ($exchangeOrder['clientOid'] ?? Str::uuid()->toString()),
            'type' => $type,
            'status' => $status,
            'side' => mb_strtoupper((string) ($exchangeOrder['side'] ?? 'BUY')),
            'position_side' => $position->direction,
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

        try {
            $response = $stop
                ? $this->account->withApi()->getStopOrders($properties)
                : $this->account->withApi()->getCurrentOpenOrders($properties);

            $body = json_decode((string) $response->getBody(), associative: true);
        } catch (Throwable $e) {
            $kind = $stop ? 'getStopOrders' : 'getCurrentOpenOrders';
            $this->report->warning("KuCoin {$kind} failed for {$symbol} on account #{$this->account->id}: {$e->getMessage()}");

            return [];
        }

        $list = $body['data']['items'] ?? $body['data'] ?? [];

        return is_array($list) ? $list : [];
    }

    /**
     * Group raw fills by orderId, sum qty, average price (qty-weighted).
     * Same shape contract the Binance recoverer uses.
     */
    protected function bucketFillsByOrderId(array $fills, string $symbol): array
    {
        $byOrder = [];
        foreach ($fills as $fill) {
            $orderId = (string) ($fill['orderId'] ?? $fill['id'] ?? '');
            if ($orderId === '') {
                continue;
            }

            $qty = (string) ($fill['size'] ?? $fill['filledSize'] ?? '0');
            $price = (string) ($fill['price'] ?? '0');
            $time = (int) ($fill['tradeTime'] ?? $fill['createdAt'] ?? 0);

            if (! isset($byOrder[$orderId])) {
                $byOrder[$orderId] = [
                    'orderId' => $orderId,
                    'symbol' => $symbol,
                    'side' => mb_strtoupper((string) ($fill['side'] ?? '')),
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
            $avgPrice = Math::gt((string) $totalQty, '0')
                ? bcdiv($row['price_qty_sum'], $totalQty, 8)
                : '0';

            $orders[] = [
                'orderId' => $row['orderId'],
                'symbol' => $row['symbol'],
                'side' => $row['side'],
                'price' => $avgPrice,
                'size' => $totalQty,
                'createdAt' => $row['time_max'],
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

        $raw = mb_strtoupper((string) ($exchangeOrder['type'] ?? 'LIMIT'));

        return match ($raw) {
            'LIMIT' => 'LIMIT',
            'MARKET' => 'MARKET',
            default => $raw,
        };
    }

    protected function mapOrderStatus(array $exchangeOrder): string
    {
        $raw = mb_strtolower((string) ($exchangeOrder['status'] ?? 'open'));

        return match ($raw) {
            'open', 'active', 'new' => 'NEW',
            'match', 'partially_filled' => 'PARTIALLY_FILLED',
            'done', 'filled' => 'FILLED',
            'cancelled', 'canceled' => 'CANCELLED',
            default => mb_strtoupper($raw),
        };
    }
}
