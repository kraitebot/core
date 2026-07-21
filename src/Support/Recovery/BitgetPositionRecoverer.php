<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Recovery;

use Illuminate\Support\Str;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\ApiDataMappers\Bitget\BitgetApiDataMapper;
use Kraite\Core\Support\ApiDataMappers\Bitget\BitgetProductContext;
use Kraite\Core\Support\Math;
use Kraite\Core\Support\ValueObjects\ApiProperties;
use Throwable;

/**
 * BitgetPositionRecoverer
 *
 * Recovers Bitget USDT-FUTURES or USDC-FUTURES accounts from either API family:
 *
 *   - Classic uses v2 mix position, order, plan-order, and fill endpoints
 *   - Unified uses v3 position, order, strategy-order, and fill endpoints
 *
 * Bitget's `tradeSide=close` reporting on fills is hedge-mode-specific
 * (account 4 today). The bot's existing trades-mapper already
 * normalises this — we delegate fill resolution to the mapper rather
 * than re-implementing it here. The position-snapshot keys are
 * `symbol:LONG/SHORT/BOTH` after the recent mode-aware change to
 * MapsPositionsQuery.
 */
final class BitgetPositionRecoverer extends AbstractPositionRecoverer
{
    /**
     * Open positions. The mapper-resolved shape is keyed by
     * `symbol:DIRECTION` — we collapse to a flat list because the base
     * class iterates positionally.
     */
    protected function fetchOpenPositions(): array
    {
        $response = $this->account->apiQueryPositions();
        $positions = $response->result ?? [];

        $open = [];
        foreach ($positions as $p) {
            $size = (string) ($p['total'] ?? $p['size'] ?? '0');
            if (Math::equal($size, '0')) {
                continue;
            }

            // Bitget uses openPriceAvg as the entry; breakEvenPrice is
            // available too and is what WAP keys off, so prefer that.
            $p['_openingPrice'] = (string) ($p['breakEvenPrice'] ?? $p['openPriceAvg'] ?? '0');
            $open[] = $p;
        }

        return $open;
    }

    /**
     * Live pending orders + plan orders, both filtered to the symbol.
     */
    protected function fetchOpenOrders(Position $position, array $exchangePosition): array
    {
        $symbol = (string) ($exchangePosition['symbol'] ?? $position->exchangeSymbol->asset);

        $quote = $position->exchangeSymbol->quote;
        $direction = (string) $position->direction;
        $regular = $this->fetchPendingOrders($symbol, $quote, $direction);
        $plan = $this->fetchPlanOrders($symbol, $quote, $direction);

        return [...$regular, ...$plan];
    }

    /**
     * Per-symbol fill history via the Classic v2 or Unified v3 fills endpoint. We bucket
     * multi-row fills by orderId to land one Order per unique exchange
     * order, mirroring the Binance recoverer.
     *
     * Like Binance, this list contains fills from PRIOR closed
     * positions on the same symbol — we window the slice that built
     * the currently-open slot by walking newest-first and stopping
     * when the running net qty equals `positionAmt`. Bitget's open vs
     * close discriminator is `tradeSide`, not `side` (in hedge mode
     * `side` always reports the original opening direction; tradeSide
     * tells us which leg of the position the fill applied to).
     */
    protected function fetchHistoricalFills(Position $position, array $exchangePosition): array
    {
        $symbol = (string) ($exchangePosition['symbol'] ?? $position->exchangeSymbol->asset);
        $positionAmt = (float) $this->absQuantity($exchangePosition);

        $properties = new ApiProperties;
        $properties->set('relatable', $position);
        $properties->set('account', $this->account);
        $properties->set('options.symbol', $symbol);
        $properties->set(
            'options.productType',
            BitgetProductContext::fromQuote($position->exchangeSymbol->quote)->productType
        );

        try {
            $response = $this->account->withApi()->accountTrades($properties);
            $body = json_decode((string) $response->getBody(), associative: true);
        } catch (Throwable $e) {
            $this->report->warning("Bitget accountTrades fetch failed for {$symbol} on account #{$this->account->id}: {$e->getMessage()}");

            return [];
        }

        $data = $body['data'] ?? [];
        $fills = is_array($data)
            ? ($data['fillList'] ?? $data['list'] ?? [])
            : [];
        if (! is_array($fills) || $fills === []) {
            return [];
        }

        $fills = array_values(array_filter(
            array_map(fn (array $fill): array => $this->normalizeFill($fill), $fills),
            fn (array $fill): bool => $this->fillBelongsToPosition($fill, $position, $symbol),
        ));

        // Window the fill list to only those that built the current
        // open slot. Bitget orders fills newest-first by default; we
        // sort defensively. The walk subtracts entry-fill qty (open)
        // and adds close-fill qty (close) going back in time — when
        // running hits 0, the position was born.
        $fills = $this->windowFillsToCurrentPosition($fills, $positionAmt);

        $byOrder = [];
        foreach ($fills as $fill) {
            $orderId = (string) ($fill['orderId'] ?? '');
            if ($orderId === '') {
                continue;
            }

            $qty = (string) ($fill['baseVolume'] ?? '0');
            $price = (string) ($fill['price'] ?? '0');
            $time = (int) ($fill['cTime'] ?? 0);

            if (! isset($byOrder[$orderId])) {
                $byOrder[$orderId] = [
                    'orderId' => $orderId,
                    'symbol' => $symbol,
                    'side' => mb_strtoupper((string) ($fill['side'] ?? '')),
                    'tradeSide' => (string) ($fill['tradeSide'] ?? ''),
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
                'tradeSide' => $row['tradeSide'],
                'price' => $avgPrice,
                'baseVolume' => $totalQty,
                'cTime' => $row['time_max'],
                '_localType' => $row['tradeSide'] === 'open' ? 'MARKET' : 'MARKET-CANCEL',
                '_localStatus' => 'FILLED',
            ];
        }

        return $orders;
    }

    /**
     * Map one Bitget order/fill row to local Order columns. Type
     * resolution covers both regular orders (orderType=limit|market)
     * and plan orders (planType=normal_plan|pos_loss|pos_profit|...).
     */
    protected function toLocalOrderAttributes(Position $position, array $exchangeOrder, bool $isFilled): array
    {
        $exchangeOrderId = (string) ($exchangeOrder['orderId'] ?? '');
        $isPlan = ! empty($exchangeOrder['planType']);

        $type = $exchangeOrder['_localType'] ?? $this->mapOrderType($exchangeOrder);
        $status = $exchangeOrder['_localStatus'] ?? $this->mapOrderStatus($exchangeOrder);

        // Bitget returns numeric fields as strings — including empty
        // strings instead of null when a field doesn't apply (e.g.
        // `price` on a plan order, `triggerPrice` on a regular limit).
        // The `??` chain only fires on null, so we use a helper that
        // falls through both null AND empty-string values.
        $price = $this->firstNonEmpty([
            $exchangeOrder['price'] ?? null,
            $exchangeOrder['triggerPrice'] ?? null,
            $exchangeOrder['stopLossTriggerPrice'] ?? null,
            $exchangeOrder['stopSurplusTriggerPrice'] ?? null,
            $exchangeOrder['executePrice'] ?? null,
        ], '0');

        $quantity = $this->firstNonEmpty([
            $exchangeOrder['size'] ?? null,
            $exchangeOrder['qty'] ?? null,
            $exchangeOrder['baseVolume'] ?? null,
            $exchangeOrder['filledQty'] ?? null,
            $exchangeOrder['cumExecQty'] ?? null,
        ], '0');

        $rawTime = $exchangeOrder['cTime']
            ?? $exchangeOrder['createdTime']
            ?? $exchangeOrder['uTime']
            ?? $exchangeOrder['updatedTime']
            ?? null;
        $openedAt = null;
        if ($rawTime !== null) {
            $secs = (int) $rawTime;
            $secs = $secs > 9_999_999_999 ? intdiv($secs, 1000) : $secs;
            $openedAt = date('Y-m-d H:i:s', $secs);
        }

        $filledAt = $isFilled ? $openedAt : null;

        // Bitget's `side` on fills is buy/sell (lowercase). Position
        // side: in hedge mode `posSide=long|short`, in one-way mode
        // empty / `BOTH` — fall back to the position's bot-side
        // direction when the response field is absent.
        $side = mb_strtoupper((string) ($exchangeOrder['side'] ?? 'BUY'));
        $positionSide = mb_strtoupper((string) ($exchangeOrder['posSide'] ?? $position->direction));

        return [
            'position_id' => $position->id,
            'exchange_order_id' => $exchangeOrderId,
            'client_order_id' => (string) ($exchangeOrder['clientOid'] ?? Str::uuid()->toString()),
            'type' => $type,
            'status' => $status,
            'side' => $side,
            'position_side' => $positionSide === 'BOTH' ? $position->direction : $positionSide,
            'is_algo' => $isPlan,
            'price' => $price,
            'quantity' => $quantity,
            'reference_price' => $price,
            'reference_quantity' => $quantity,
            'reference_status' => $status,
            'opened_at' => $openedAt,
            'filled_at' => $filledAt,
        ];
    }

    /**
     * Walk Bitget fills newest-first, accumulating signed qty until
     * the running total equals `positionAmt`. The fills returned are
     * exactly those that built the currently-open slot; older fills
     * belong to PRIOR closed positions on the same symbol and must
     * not be attributed here.
     *
     * Sign convention: Bitget's `tradeSide` is the open/close flag.
     * Walking back in time, an `open` fill subtracts from the running
     * size (because before that fill the slot was smaller) and a
     * `close` fill adds (because before the close that qty was still
     * part of the slot).
     *
     * @param  array<int, array<string, mixed>>  $fills
     * @return array<int, array<string, mixed>>
     */
    protected function windowFillsToCurrentPosition(array $fills, float $positionAmt): array
    {
        if ($positionAmt <= 0.0 || $fills === []) {
            return $fills;
        }

        usort($fills, static fn (array $a, array $b): int => (int) ($b['cTime'] ?? 0) <=> (int) ($a['cTime'] ?? 0));

        $running = $positionAmt;
        $epsilon = 1e-9;
        $kept = [];

        foreach ($fills as $fill) {
            if ($running <= $epsilon) {
                break;
            }

            $kept[] = $fill;

            $qty = (float) ($fill['baseVolume'] ?? 0);
            $tradeSide = mb_strtolower((string) ($fill['tradeSide'] ?? ''));

            $running += $tradeSide === 'open' ? -$qty : +$qty;
        }

        return array_values($kept);
    }

    /**
     * Pick the first numeric-looking value from a candidate list,
     * treating both null and empty string as "missing". Bitget often
     * sends `""` (empty string) for inapplicable numeric fields rather
     * than null, which sneaks past the standard `??` chain.
     *
     * @param  array<int, mixed>  $candidates
     */
    protected function firstNonEmpty(array $candidates, string $default): string
    {
        foreach ($candidates as $value) {
            if ($value === null) {
                continue;
            }
            $stringified = (string) $value;
            if ($stringified === '') {
                continue;
            }

            return $stringified;
        }

        return $default;
    }

    /**
     * Live LIMIT / PROFIT-LIMIT / MARKET orders.
     */
    protected function fetchPendingOrders(string $symbol, string $quote, string $direction): array
    {
        if ($this->batchedOpenOrders !== null) {
            $list = $this->batchedOpenOrders;
        } else {
            $context = BitgetProductContext::fromQuote($quote);

            $properties = new ApiProperties;
            $properties->set('relatable', $this->account);
            $properties->set('account', $this->account);
            $properties->set('options.symbol', $symbol);
            $properties->set('options.productType', $context->productType);

            try {
                $response = $this->account->withApi()->getCurrentOpenOrders($properties);
                $body = json_decode((string) $response->getBody(), associative: true);
            } catch (Throwable $e) {
                $this->report->warning("Bitget getCurrentOpenOrders failed for {$symbol} on account #{$this->account->id}: {$e->getMessage()}");

                return [];
            }

            $data = $body['data'] ?? [];
            $list = is_array($data)
                ? ($data['entrustedList'] ?? $data['list'] ?? [])
                : [];
        }

        if (! is_array($list)) {
            return [];
        }

        return array_map(
            fn (array $order): array => $this->normalizeRegularOrder($order),
            $this->filterOrdersForPosition($list, $symbol, $direction),
        );
    }

    /**
     * Live plan orders (STOP-MARKET, position TP/SL).
     */
    protected function fetchPlanOrders(string $symbol, string $quote, string $direction): array
    {
        if ($this->batchedAlgoOrders !== null) {
            $list = $this->batchedAlgoOrders;
        } else {
            $context = BitgetProductContext::fromQuote($quote);

            $properties = new ApiProperties;
            $properties->set('relatable', $this->account);
            $properties->set('account', $this->account);
            $properties->set('options.symbol', $symbol);
            $properties->set('options.productType', $context->productType);
            // profit_loss covers BOTH stop-loss and take-profit plan types,
            // including the position-level pos_profit / pos_loss flavours
            // that Bitget attaches via place-pos-tpsl.
            $properties->set('options.planType', 'profit_loss');

            try {
                $response = $this->account->withApi()->getPlanOrders($properties);
                $body = json_decode((string) $response->getBody(), associative: true);
            } catch (Throwable $e) {
                $this->report->warning("Bitget getPlanOrders failed for {$symbol} on account #{$this->account->id}: {$e->getMessage()}");

                return [];
            }

            $data = $body['data'] ?? [];
            $list = is_array($data)
                ? ($data['entrustedList'] ?? $data['list'] ?? (array_is_list($data) ? $data : []))
                : [];
        }

        if (! is_array($list)) {
            return [];
        }

        $mapper = new BitgetApiDataMapper;
        $snapshots = [];
        foreach ($list as $order) {
            if (! is_array($order)) {
                continue;
            }

            array_push($snapshots, ...$mapper->normalizePlanOrderSnapshots($order));
        }

        return array_map(
            fn (array $order): array => $this->normalizeStrategyOrder($order, $direction),
            $this->filterOrdersForPosition($snapshots, $symbol, $direction),
        );
    }

    /**
     * Map Bitget's order-type vocabulary to local types. Plan orders
     * disambiguate via planType: pos_profit / profit_plan → PROFIT-LIMIT
     * (or PROFIT-MARKET for market-execute), pos_loss / loss_plan /
     * normal_plan → STOP-MARKET.
     */
    protected function mapOrderType(array $exchangeOrder): string
    {
        $planType = (string) ($exchangeOrder['planType'] ?? '');
        if ($planType !== '') {
            return match ($planType) {
                'pos_profit', 'profit_plan' => 'PROFIT-LIMIT',
                'pos_loss', 'loss_plan', 'normal_plan', 'track_plan' => 'STOP-MARKET',
                default => 'STOP-MARKET',
            };
        }

        $orderType = mb_strtolower((string) ($exchangeOrder['orderType'] ?? 'limit'));

        return match ($orderType) {
            'limit' => 'LIMIT',
            'market' => 'MARKET',
            default => mb_strtoupper($orderType),
        };
    }

    /**
     * Map Bitget's `state` / `planStatus` to our local status set.
     */
    protected function mapOrderStatus(array $exchangeOrder): string
    {
        $raw = mb_strtolower((string) (
            $exchangeOrder['state']
            ?? $exchangeOrder['orderStatus']
            ?? $exchangeOrder['planStatus']
            ?? $exchangeOrder['status']
            ?? 'new'
        ));

        return match ($raw) {
            'new', 'live', 'not_trigger', 'pending', 'submitting' => 'NEW',
            'partially_filled', 'partial-fill' => 'PARTIALLY_FILLED',
            'filled', 'full-fill', 'executed', 'triggered' => 'FILLED',
            'cancelled', 'canceled' => 'CANCELLED',
            default => mb_strtoupper($raw),
        };
    }

    /** @param array<string, mixed> $fill */
    private function normalizeFill(array $fill): array
    {
        $fill['tradeId'] ??= $fill['execId'] ?? '';
        $fill['price'] ??= $fill['execPrice'] ?? '0';
        $fill['baseVolume'] ??= $fill['execQty'] ?? '0';
        $fill['cTime'] ??= $fill['createdTime'] ?? '0';
        $fill['profit'] ??= $fill['execPnl'] ?? '0';

        return $fill;
    }

    /** @param array<string, mixed> $fill */
    private function fillBelongsToPosition(array $fill, Position $position, string $symbol): bool
    {
        if (mb_strtoupper((string) ($fill['symbol'] ?? '')) !== mb_strtoupper($symbol)) {
            return false;
        }

        if (! $position->account->isHedgeMode()) {
            return true;
        }

        $direction = mb_strtolower((string) ($fill['posSide'] ?? ''));

        if ($direction === '') {
            $side = mb_strtolower((string) ($fill['side'] ?? ''));
            $tradeSide = mb_strtolower((string) ($fill['tradeSide'] ?? ''));
            $direction = match ([$tradeSide, $side]) {
                ['open', 'buy'], ['close', 'sell'] => 'long',
                ['open', 'sell'], ['close', 'buy'] => 'short',
                default => '',
            };
        }

        return $direction === mb_strtolower((string) $position->direction);
    }

    /** @param array<string, mixed> $order */
    private function normalizeRegularOrder(array $order): array
    {
        $order['state'] ??= $order['orderStatus'] ?? 'new';
        $order['size'] ??= $order['qty'] ?? '0';
        $order['filledQty'] ??= $order['cumExecQty'] ?? '0';
        $order['priceAvg'] ??= $order['avgPrice'] ?? '0';
        $order['cTime'] ??= $order['createdTime'] ?? null;

        return $order;
    }

    /** @param array<string, mixed> $order */
    private function normalizeStrategyOrder(array $order, string $direction): array
    {
        $hasStopLoss = Math::gt((string) ($order['stopLoss'] ?? '0'), '0');
        $hasTrigger = Math::gt((string) ($order['triggerPrice'] ?? '0'), '0');
        $order['planType'] ??= $hasTrigger ? 'normal_plan' : ($hasStopLoss ? 'pos_loss' : 'pos_profit');
        $order['planStatus'] ??= $order['status'] ?? 'live';
        $order['size'] ??= $order['qty'] ?? '0';
        $order['stopLossTriggerPrice'] ??= $hasStopLoss ? $order['stopLoss'] : '';
        $order['stopSurplusTriggerPrice'] ??= ! $hasStopLoss && ! $hasTrigger
            ? ($order['takeProfit'] ?? '')
            : '';
        $order['cTime'] ??= $order['createdTime'] ?? null;

        if (! $hasTrigger && empty($order['side'])) {
            $order['side'] = mb_strtoupper($direction) === 'LONG' ? 'sell' : 'buy';
        }

        return $order;
    }

    /**
     * @param  array<int, mixed>  $orders
     * @return array<int, array<string, mixed>>
     */
    private function filterOrdersForPosition(array $orders, string $symbol, string $direction): array
    {
        $symbol = mb_strtoupper($symbol);
        $direction = mb_strtoupper($direction);

        return array_values(array_filter($orders, function (mixed $order) use ($symbol, $direction): bool {
            if (! is_array($order)
                || mb_strtoupper((string) ($order['symbol'] ?? '')) !== $symbol) {
                return false;
            }

            if (! $this->account->isHedgeMode()) {
                return true;
            }

            $positionSide = mb_strtoupper((string) (
                $order['positionSide']
                ?? $order['posSide']
                ?? $order['holdSide']
                ?? ''
            ));

            return $positionSide === $direction;
        }));
    }
}
