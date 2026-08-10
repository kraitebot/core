<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Drift;

use Kraite\Core\Enums\PositionPresence;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\Math;
use Kraite\Core\Support\PositionSnapshot;
use Throwable;
use UnexpectedValueException;

/**
 * DriftCheckService
 *
 * Compares each open (or recently-open) position on an account with what
 * the exchange currently reports, plus every order it carries. The same
 * algorithm powers the admin-side drift dashboard at admin.kraite.com,
 * lifted here so the proactive 5-minute spotter cron can reuse it without
 * duplicating logic.
 *
 * Designed for ACCOUNT-level batching: a single call pulls all positions
 * + all open orders + all algo/plan/stop orders + per-symbol leverage
 * config in one round-trip, then pairs them locally — keeps the API
 * footprint flat regardless of how many positions the account holds.
 */
final class DriftCheckService implements DriftChecker
{
    /**
     * Position-level fields compared between DB and exchange.
     */
    public const POSITION_DRIFT_FIELDS = ['quantity', 'entry_price', 'leverage', 'margin', 'margin_mode'];

    /**
     * Order-level fields compared between DB and exchange.
     */
    public const ORDER_DRIFT_FIELDS = ['status', 'side', 'type', 'price', 'quantity'];

    /**
     * The conditional-order endpoint that completes each exchange's
     * open-order snapshot. A failure on one of these calls makes the whole
     * drift snapshot inconclusive; treating the partial response as complete
     * would manufacture db_only protection-order alerts.
     */
    private const CONDITIONAL_ORDER_QUERY_BY_EXCHANGE = [
        'binance' => 'apiQueryAlgoOrders',
        'bitget' => 'apiQueryPlanOrders',
        'bybit' => 'apiQueryStopOrders',
        'kucoin' => 'apiQueryStopOrders',
    ];

    /**
     * Numeric fields use bccomp at 18-decimal precision to absorb the
     * scale gap between exchange-reported precision and what we store.
     */
    private const NUMERIC_FIELDS = ['quantity', 'price', 'entry_price', 'margin'];

    /**
     * Price-like fields where exchange-side reports volume-weighted
     * averages with finer precision than we persist. The 0.1% tolerance
     * absorbs broker-side averaging without letting real desync pass.
     */
    private const PRICE_TOLERANCE_FIELDS = ['price', 'entry_price'];

    private const PRICE_TOLERANCE = '0.001';

    /**
     * Order statuses worth comparing. FILLED orders are included so the
     * weighted-average entry recomputation has the right inputs, but a
     * FILLED order absent from the exchange-side open-orders endpoint is
     * NOT flagged as db_only — it's expected (FILLED orders graduate off).
     */
    private const COMPARATOR_ORDER_STATUSES = ['NEW', 'PARTIALLY_FILLED', 'FILLED'];

    /**
     * Position statuses considered "still open and visible to the
     * exchange". Anything else (closed/cancelled/failed) is excluded
     * from drift analysis since those positions shouldn't have any
     * paired exchange position.
     */
    private const OPEN_POSITION_STATUSES = ['new', 'opening', 'active', 'syncing', 'waping', 'closing', 'cancelling'];

    /**
     * Kraite's internal type labels ↔ what the exchange may report
     * for the same functional order.
     */
    private const TYPE_ALIASES = [
        'PROFIT-LIMIT' => ['LIMIT', 'TAKE_PROFIT', 'TAKE_PROFIT_LIMIT'],
        'PROFIT-MARKET' => ['MARKET', 'TAKE_PROFIT_MARKET'],
        'STOP-LIMIT' => ['STOP', 'STOP_LIMIT'],
        'STOP-MARKET' => ['STOP_MARKET'],
        'TRAILING-STOP' => ['TRAILING_STOP_MARKET'],
    ];

    /**
     * Cross-exchange order-status equivalents.
     */
    private const STATUS_ALIASES = [
        'NEW' => ['LIVE', 'ACTIVE', 'OPEN'],
        'PARTIALLY_FILLED' => ['PARTIAL_FILL', 'PARTIALLYFILLED', 'PARTIALLY-FILLED'],
        'FILLED' => ['CLOSED', 'EXECUTED'],
    ];

    /**
     * Types whose exchange-reported quantity is the WHOLE position at
     * trigger time. They report 0 because nothing is bound — comparing
     * against the DB's intended qty would always flag false drift.
     */
    private const CLOSE_POSITION_TYPES = [
        'PROFIT-LIMIT', 'PROFIT-MARKET', 'STOP-LIMIT', 'STOP-MARKET', 'TRAILING-STOP',
    ];

    /**
     * Audit a whole account in one batched API roundtrip.
     *
     * Fetches DB positions + every relevant live endpoint on the exchange,
     * then delegates to {@see analyse} for the pure pairing/comparison
     * step. The fetch/analyse split exists so the algorithm can be
     * exercised in tests without standing up a real exchange client.
     */
    public function analyseAccount(Account $account): AccountDriftReport
    {
        $dbPositions = $account->positions()
            ->whereIn('status', self::OPEN_POSITION_STATUSES)
            ->with([
                'orders' => fn ($q) => $q->whereIn('status', self::COMPARATOR_ORDER_STATUSES),
            ])
            ->get();

        $exchangePositions = [];
        $exchangeOrders = [];
        $symbolConfigs = [];
        $apiError = null;

        try {
            $positionsResponse = $account->apiQueryPositions();

            if (! PositionSnapshot::fromApiResponse($account, $positionsResponse)->isValid()) {
                throw new UnexpectedValueException(sprintf(
                    'Invalid %s positions response during drift audit for account %d.',
                    $account->apiSystem->canonical,
                    $account->id,
                ));
            }

            $positionsResult = $positionsResponse->result;
            $exchangePositions = collect($positionsResult)
                ->filter(fn ($pos) => ! Math::equal((string) ($pos['positionAmt'] ?? $pos['size'] ?? $pos['contracts'] ?? $pos['total'] ?? '0'), '0'))
                ->values()
                ->all();

            $exchangeOrders = $account->apiQueryOpenOrders()->result ?? [];
        } catch (Throwable $e) {
            $apiError = $e->getMessage();
        }

        if ($apiError !== null) {
            return new AccountDriftReport(
                account: $account,
                positions: [],
                orphanOrders: [],
                apiError: $apiError,
            );
        }

        if (method_exists($account, 'apiQuerySymbolConfig')) {
            try {
                $symbolConfigs = $account->apiQuerySymbolConfig()->result ?? [];
            } catch (Throwable $e) {
                // Endpoint not supported / transient — treat as no signal.
            }
        }

        $conditionalOrderMethod = self::CONDITIONAL_ORDER_QUERY_BY_EXCHANGE[
            mb_strtolower((string) $account->apiSystem->canonical)
        ] ?? null;

        if ($conditionalOrderMethod !== null) {
            try {
                $conditionalOrders = $account->{$conditionalOrderMethod}()->result ?? [];
                if (is_array($conditionalOrders) && $conditionalOrders !== []) {
                    $exchangeOrders = array_merge($exchangeOrders, $conditionalOrders);
                }
            } catch (Throwable $e) {
                return new AccountDriftReport(
                    account: $account,
                    positions: [],
                    orphanOrders: [],
                    apiError: $e->getMessage(),
                );
            }
        }

        return $this->analyse($account, $dbPositions, $exchangePositions, $exchangeOrders, $symbolConfigs, $apiError);
    }

    public function isExchangePositionOpen(Account $account, Position $position): bool
    {
        try {
            $response = $account->apiQueryPositions();
        } catch (Throwable $exception) {
            throw new UnexpectedValueException(sprintf(
                'Unable to confirm %s position %d: %s',
                $account->apiSystem->canonical,
                $position->id,
                $exception->getMessage(),
            ), previous: $exception);
        }

        $snapshot = PositionSnapshot::fromApiResponse($account, $response);

        if (! $snapshot->isValid()) {
            throw new UnexpectedValueException(sprintf(
                'Invalid %s positions response while confirming drift for position %d.',
                $account->apiSystem->canonical,
                $position->id,
            ));
        }

        return match ($snapshot->presenceOf($position)) {
            PositionPresence::Open => true,
            PositionPresence::Flat => false,
            PositionPresence::Unknown => throw new UnexpectedValueException(sprintf(
                'Unknown %s position presence while confirming drift for position %d.',
                $account->apiSystem->canonical,
                $position->id,
            )),
        };
    }

    /**
     * Pure pairing/comparison step. Takes pre-fetched DB and exchange data
     * and returns the report. Public so tests can drive it without HTTP.
     *
     * @param  iterable<int, Position>  $dbPositions
     * @param  array<int, array<string, mixed>>  $exchangePositions
     * @param  array<int, array<string, mixed>>  $exchangeOrders
     * @param  array<string, array<string, mixed>>  $symbolConfigs
     */
    public function analyse(
        Account $account,
        iterable $dbPositions,
        array $exchangePositions,
        array $exchangeOrders,
        array $symbolConfigs = [],
        ?string $apiError = null,
    ): AccountDriftReport {
        [$pairs, $matched] = $this->buildPairs($account, $dbPositions, $exchangePositions, $exchangeOrders, $symbolConfigs);
        $orphans = $this->buildOrphanOrders($exchangeOrders, $matched);

        return new AccountDriftReport(
            account: $account,
            positions: $pairs,
            orphanOrders: $orphans,
            apiError: $apiError,
        );
    }

    /**
     * @param  iterable<int, Position>  $dbPositions
     * @param  array<int, array<string, mixed>>  $exchangePositions
     * @param  array<int, array<string, mixed>>  $exchangeOrders
     * @param  array<string, array<string, mixed>>  $symbolConfigs
     * @return array{0: PositionDriftReport[], 1: array<int, true>}
     */
    private function buildPairs(
        Account $account,
        iterable $dbPositions,
        array $exchangePositions,
        array $exchangeOrders,
        array $symbolConfigs,
    ): array {
        $accountMarginMode = mb_strtoupper((string) ($account->margin_mode ?? ''));

        $exchByKey = [];
        foreach ($exchangePositions as $pos) {
            $symbol = $pos['symbol'] ?? null;
            if (! $symbol) {
                continue;
            }
            $exchByKey[$symbol.'|'.$this->normalizeDirection($pos)] = $pos;
        }

        $exchOrdersByCid = [];
        $exchOrdersByXid = [];
        foreach ($exchangeOrders as $idx => $order) {
            $cid = $order['clientOrderId'] ?? $order['orderLinkId'] ?? $order['clientAlgoId'] ?? null;
            $xid = $order['orderId'] ?? $order['id'] ?? $order['algoId'] ?? null;
            $functionalType = $this->functionalOrderType((string) ($order['_orderType'] ?? $order['type'] ?? $order['orderType'] ?? ''));
            if ($cid !== null && $cid !== '') {
                $exchOrdersByCid[(string) $cid] = $idx;
                $exchOrdersByCid[(string) $cid.'|'.$functionalType] = $idx;
            }
            if ($xid !== null && $xid !== '') {
                $exchOrdersByXid[(string) $xid] = $idx;
                $exchOrdersByXid[(string) $xid.'|'.$functionalType] = $idx;
            }
        }

        $matched = [];
        $pairs = [];
        $seenKeys = [];

        foreach ($dbPositions as $dbPos) {
            $symbol = $dbPos->parsed_trading_pair ?? $dbPos->exchangeSymbol?->symbol;
            if (! $symbol) {
                continue;
            }
            $direction = (string) $dbPos->direction;
            $key = $symbol.'|'.$direction;
            $seenKeys[$key] = true;
            $exchPos = $exchByKey[$key] ?? null;
            $pairs[] = $this->buildPair(
                $symbol,
                $direction,
                $dbPos,
                $exchPos,
                $accountMarginMode,
                $exchangeOrders,
                $exchOrdersByCid,
                $exchOrdersByXid,
                $matched,
                $symbolConfigs[$symbol] ?? null,
            );
        }

        // Exchange positions that have no DB sibling = exchange_only.
        foreach ($exchByKey as $key => $exchPos) {
            if (isset($seenKeys[$key])) {
                continue;
            }
            [$symbol, $direction] = explode('|', $key, 2);
            $pairs[] = $this->buildPair(
                $symbol,
                $direction,
                null,
                $exchPos,
                $accountMarginMode,
                $exchangeOrders,
                $exchOrdersByCid,
                $exchOrdersByXid,
                $matched,
                $symbolConfigs[$symbol] ?? null,
            );
        }

        return [$pairs, $matched];
    }

    /**
     * @param  array<int, array<string, mixed>>  $exchangeOrders
     * @param  array<string, int>  $exchOrdersByCid
     * @param  array<string, int>  $exchOrdersByXid
     * @param  array<int, true>  $matched
     * @param  array<string, mixed>|null  $symbolConfig
     */
    private function buildPair(
        string $symbol,
        string $direction,
        ?Position $dbPos,
        ?array $exchPos,
        string $accountMarginMode,
        array $exchangeOrders,
        array $exchOrdersByCid,
        array $exchOrdersByXid,
        array &$matched,
        ?array $symbolConfig = null,
    ): PositionDriftReport {
        $dbPosData = $dbPos ? $this->dbPositionData($dbPos, $accountMarginMode) : null;
        $exchPosData = $exchPos ? $this->exchangePositionData($exchPos, $symbolConfig) : null;

        // Replace the stored opening_price snapshot with the
        // weighted-average across FILLED entry-side orders so the
        // comparator has the same shape as the exchange's running
        // entryPrice. Snapshot-based compare would always drift.
        if ($dbPosData && $dbPos) {
            $avg = $this->computeWeightedAvgEntry($dbPos, $direction);
            if ($avg !== null) {
                $dbPosData['entry_price'] = $avg;
            }
        }

        $dbIsActive = $dbPosData !== null && ($dbPosData['status'] ?? null) === 'active';

        $positionDriftFields = [];
        if ($dbPosData && $exchPosData && $dbIsActive) {
            foreach (self::POSITION_DRIFT_FIELDS as $field) {
                if (! $this->valuesEqual($field, $dbPosData[$field] ?? null, $exchPosData[$field] ?? null)) {
                    $positionDriftFields[] = $field;
                }
            }
        }

        $orders = [];
        if ($dbPos) {
            foreach ($dbPos->orders as $dbOrder) {
                $cid = $dbOrder->client_order_id;
                $xid = $dbOrder->exchange_order_id;
                $functionalType = $this->functionalOrderType((string) $dbOrder->type);

                $exchIdx = null;
                if ($cid && isset($exchOrdersByCid[(string) $cid.'|'.$functionalType])) {
                    $exchIdx = $exchOrdersByCid[(string) $cid.'|'.$functionalType];
                } elseif ($xid && isset($exchOrdersByXid[(string) $xid.'|'.$functionalType])) {
                    $exchIdx = $exchOrdersByXid[(string) $xid.'|'.$functionalType];
                } elseif ($cid && isset($exchOrdersByCid[(string) $cid])) {
                    $exchIdx = $exchOrdersByCid[(string) $cid];
                } elseif ($xid && isset($exchOrdersByXid[(string) $xid])) {
                    $exchIdx = $exchOrdersByXid[(string) $xid];
                }

                $exchOrder = $exchIdx !== null ? $exchangeOrders[$exchIdx] : null;
                if ($exchIdx !== null) {
                    $matched[$exchIdx] = true;
                }

                $orders[] = $this->buildOrderPair(
                    $this->dbOrderData($dbOrder),
                    $exchOrder ? $this->exchangeOrderData($exchOrder) : null,
                );
            }
        }

        // Exchange orders for this (symbol, direction) that didn't pair
        // with a DB row → exchange_only.
        foreach ($exchangeOrders as $idx => $exchOrder) {
            if (isset($matched[$idx])) {
                continue;
            }
            if (($exchOrder['symbol'] ?? null) !== $symbol) {
                continue;
            }
            $exchDirection = $this->inferOrderDirection($exchOrder);
            if ($exchDirection !== null && $exchDirection !== $direction) {
                continue;
            }
            // Without positionSide on the exchange row we can only attach
            // to the pair when there's a DB position to anchor direction.
            if ($exchDirection === null && $dbPos === null) {
                continue;
            }
            $matched[$idx] = true;
            $orders[] = $this->buildOrderPair(null, $this->exchangeOrderData($exchOrder));
        }

        // Mid-flight DB state (opening/syncing/waping/closing/...) is
        // intentionally not steady — the dispatcher is mid-write. Mark
        // every order synced and the pair transient to avoid alarm fatigue.
        if ($dbPosData && ! $dbIsActive) {
            foreach ($orders as $i => $o) {
                $orders[$i] = new OrderDriftReport(
                    status: OrderDriftReport::STATUS_SYNCED,
                    db: $o->db,
                    exchange: $o->exchange,
                    driftFields: [],
                );
            }
        }

        $anyOrderDrift = (bool) array_filter(
            $orders,
            static fn (OrderDriftReport $o): bool => $o->status !== OrderDriftReport::STATUS_SYNCED,
        );
        $positionDrift = ! empty($positionDriftFields);

        if ($dbPosData && ! $dbIsActive) {
            $status = PositionDriftReport::STATUS_TRANSIENT;
        } elseif ($dbPosData && ! $exchPosData) {
            $status = PositionDriftReport::STATUS_DB_ONLY;
        } elseif (! $dbPosData && $exchPosData) {
            $status = PositionDriftReport::STATUS_EXCHANGE_ONLY;
        } elseif ($positionDrift || $anyOrderDrift) {
            $status = PositionDriftReport::STATUS_DRIFT;
        } else {
            $status = PositionDriftReport::STATUS_SYNCED;
        }

        return new PositionDriftReport(
            symbol: $symbol,
            direction: $direction,
            status: $status,
            positionId: $dbPos?->id,
            db: $dbPosData,
            exchange: $exchPosData,
            positionDriftFields: $positionDriftFields,
            orders: $orders,
        );
    }

    /**
     * @param  array<string, mixed>|null  $db
     * @param  array<string, mixed>|null  $exch
     */
    private function buildOrderPair(?array $db, ?array $exch): OrderDriftReport
    {
        if ($db && ! $exch) {
            // FILLED orders are historical — they no longer live on the
            // open-orders endpoint. Their absence is expected, not drift.
            if (mb_strtoupper((string) ($db['status'] ?? '')) === 'FILLED') {
                return new OrderDriftReport(OrderDriftReport::STATUS_SYNCED, $db, null, []);
            }

            return new OrderDriftReport(OrderDriftReport::STATUS_DB_ONLY, $db, null, []);
        }
        if (! $db && $exch) {
            return new OrderDriftReport(OrderDriftReport::STATUS_EXCHANGE_ONLY, null, $exch, []);
        }

        // Close-position plan/algo orders: BitGet et al. report qty=0
        // and encode position-side rather than action-side. Same logical
        // order, different encoding — skip those fields.
        $isClosePos = $this->isClosePositionType($db['type'] ?? null);
        $skipQty = $isClosePos
            && $exch !== null
            && (string) ($exch['quantity'] ?? '') !== ''
            && is_numeric((string) $exch['quantity'])
            && bccomp((string) $exch['quantity'], '0', 18) === 0;
        $skipSide = $isClosePos;

        $driftFields = [];
        foreach (self::ORDER_DRIFT_FIELDS as $field) {
            if ($field === 'quantity' && $skipQty) {
                continue;
            }
            if ($field === 'side' && $skipSide) {
                continue;
            }
            if (! $this->valuesEqual($field, $db[$field] ?? null, $exch[$field] ?? null)) {
                $driftFields[] = $field;
            }
        }

        return new OrderDriftReport(
            status: empty($driftFields) ? OrderDriftReport::STATUS_SYNCED : OrderDriftReport::STATUS_DRIFT,
            db: $db,
            exchange: $exch,
            driftFields: $driftFields,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $exchangeOrders
     * @param  array<int, true>  $matched
     * @return array<int, array<string, mixed>>
     */
    private function buildOrphanOrders(array $exchangeOrders, array $matched): array
    {
        $orphans = [];
        foreach ($exchangeOrders as $idx => $exchOrder) {
            if (isset($matched[$idx])) {
                continue;
            }
            $orphans[] = $this->exchangeOrderData($exchOrder);
        }

        return $orphans;
    }

    /**
     * @return array<string, mixed>
     */
    private function dbPositionData(Position $pos, string $accountMarginMode): array
    {
        return [
            'id' => $pos->id,
            'status' => mb_strtolower((string) $pos->status),
            'quantity' => (string) $pos->quantity,
            'entry_price' => (string) $pos->opening_price,
            'leverage' => (string) $pos->leverage,
            'margin' => (string) $pos->margin,
            'margin_mode' => $accountMarginMode,
        ];
    }

    /**
     * @param  array<string, mixed>  $pos
     * @param  array<string, mixed>|null  $symbolConfig
     * @return array<string, mixed>
     */
    private function exchangePositionData(array $pos, ?array $symbolConfig = null): array
    {
        $leverage = $pos['leverage'] ?? $symbolConfig['leverage'] ?? null;
        $margin = $pos['isolatedMargin']
            ?? $pos['positionBalance']
            ?? $pos['marginSize']
            ?? $pos['margin']
            ?? null;
        $marginMode = $pos['marginType']
            ?? $pos['marginMode']
            ?? $symbolConfig['marginType']
            ?? null;

        $isCross = mb_strtoupper((string) ($marginMode ?? '')) === 'CROSSED';
        if ($isCross
            || ($margin !== null && is_numeric((string) $margin) && bccomp((string) $margin, '0', 18) === 0)) {
            $margin = null;
        }

        $rawQty = (string) ($pos['positionAmt'] ?? $pos['size'] ?? $pos['contracts'] ?? $pos['total'] ?? '0');
        $absQty = Math::lt($rawQty, '0') ? Math::sub('0', $rawQty) : $rawQty;

        return [
            'quantity' => $absQty,
            'entry_price' => (string) ($pos['entryPrice'] ?? $pos['avgPrice'] ?? $pos['openPriceAvg'] ?? 0),
            'leverage' => $leverage !== null ? (string) $leverage : null,
            'margin' => $margin !== null ? (string) $margin : null,
            'margin_mode' => $marginMode !== null ? mb_strtoupper((string) $marginMode) : null,
        ];
    }

    /**
     * Weighted-average entry price across a position's FILLED entry-side
     * orders (BUY for LONG, SELL for SHORT). Mirrors what exchanges
     * report as the running position entryPrice.
     */
    private function computeWeightedAvgEntry(Position $dbPos, string $direction): ?string
    {
        $entrySide = mb_strtoupper($direction) === 'LONG' ? 'BUY' : 'SELL';
        $totalQty = '0';
        $weightedCost = '0';

        foreach ($dbPos->orders as $o) {
            if (mb_strtoupper((string) $o->status) !== 'FILLED') {
                continue;
            }
            if (mb_strtoupper((string) $o->side) !== $entrySide) {
                continue;
            }
            $q = (string) $o->quantity;
            $p = (string) $o->price;
            if (! is_numeric($q) || ! is_numeric($p)) {
                continue;
            }
            $totalQty = bcadd($totalQty, $q, 18);
            $weightedCost = bcadd($weightedCost, bcmul($q, $p, 18), 18);
        }

        if (bccomp($totalQty, '0', 18) <= 0) {
            return null;
        }

        return $this->trim(bcdiv($weightedCost, $totalQty, 18));
    }

    private function numericWithinTolerance(string $a, string $b): bool
    {
        if (! is_numeric($a) || ! is_numeric($b)) {
            return false;
        }

        if (Math::equal($a, $b)) {
            return true;
        }

        // Relative tolerance: |a - b| / max(|a|, |b|) ≤ tol.
        $absA = Math::lt($a, '0') ? Math::sub('0', $a) : $a;
        $absB = Math::lt($b, '0') ? Math::sub('0', $b) : $b;
        $scale = Math::gt($absA, $absB) ? $absA : $absB;
        if (Math::equal($scale, '0')) {
            return false;
        }

        $diff = Math::sub($a, $b);
        $absDiff = Math::lt($diff, '0') ? Math::sub('0', $diff) : $diff;
        $ratio = Math::div($absDiff, $scale, 12);

        return Math::lte($ratio, (string) self::PRICE_TOLERANCE);
    }

    /**
     * @return array<string, mixed>
     */
    private function dbOrderData(object $order): array
    {
        return [
            'id' => $order->id,
            'client_order_id' => $order->client_order_id,
            'exchange_order_id' => $order->exchange_order_id,
            'status' => mb_strtoupper((string) $order->status),
            'side' => mb_strtoupper((string) $order->side),
            'type' => mb_strtoupper((string) $order->type),
            'price' => (string) $order->price,
            'quantity' => (string) $order->quantity,
        ];
    }

    /**
     * @param  array<string, mixed>  $order
     * @return array<string, mixed>
     */
    private function exchangeOrderData(array $order): array
    {
        $price = $order['price'] ?? null;
        if (($price === null || (string) $price === '' || (string) $price === '0' || (string) $price === '0.0') && isset($order['_price'])) {
            $price = $order['_price'];
        }
        if (($price === null || (string) $price === '' || (string) $price === '0' || (string) $price === '0.0') && isset($order['triggerPrice'])) {
            $price = $order['triggerPrice'];
        }

        return [
            'client_order_id' => $order['clientOrderId'] ?? $order['orderLinkId'] ?? $order['clientAlgoId'] ?? null,
            'exchange_order_id' => $order['orderId'] ?? $order['id'] ?? $order['algoId'] ?? null,
            'symbol' => $order['symbol'] ?? null,
            'status' => mb_strtoupper((string) ($order['status'] ?? $order['planStatus'] ?? $order['algoStatus'] ?? '')),
            'side' => mb_strtoupper((string) ($order['side'] ?? '')),
            'type' => mb_strtoupper((string) ($order['_orderType'] ?? $order['type'] ?? $order['orderType'] ?? '')),
            'price' => (string) ($price ?? 0),
            'quantity' => (string) ($order['origQty'] ?? $order['qty'] ?? $order['size'] ?? $order['quantity'] ?? 0),
        ];
    }

    private function valuesEqual(string $field, mixed $a, mixed $b): bool
    {
        // Null on either side = "not reported" — can't meaningfully
        // compare. Doesn't apply to paired orders (pairing requires both
        // sides), only to position fields the exchange may omit.
        if ($a === null || $b === null) {
            return true;
        }
        if (in_array($field, self::NUMERIC_FIELDS, true)) {
            $sa = (string) $a;
            $sb = (string) $b;

            if ($sa === '' || $sb === '' || ! is_numeric($sa) || ! is_numeric($sb)) {
                return $sa === $sb;
            }

            if (in_array($field, self::PRICE_TOLERANCE_FIELDS, true)) {
                return $this->numericWithinTolerance($sa, $sb);
            }

            return bccomp($sa, $sb, 18) === 0;
        }
        if ($field === 'type') {
            return $this->typesEqual((string) $a, (string) $b);
        }
        if ($field === 'status') {
            return $this->statusesEqual((string) $a, (string) $b);
        }

        return mb_strtoupper((string) $a) === mb_strtoupper((string) $b);
    }

    private function statusesEqual(string $a, string $b): bool
    {
        $au = mb_strtoupper($a);
        $bu = mb_strtoupper($b);
        if ($au === $bu) {
            return true;
        }
        if (isset(self::STATUS_ALIASES[$au]) && in_array($bu, self::STATUS_ALIASES[$au], true)) {
            return true;
        }
        if (isset(self::STATUS_ALIASES[$bu]) && in_array($au, self::STATUS_ALIASES[$bu], true)) {
            return true;
        }

        return false;
    }

    private function typesEqual(string $a, string $b): bool
    {
        $au = mb_strtoupper($a);
        $bu = mb_strtoupper($b);
        if ($au === $bu) {
            return true;
        }
        if (isset(self::TYPE_ALIASES[$au]) && in_array($bu, self::TYPE_ALIASES[$au], true)) {
            return true;
        }
        if (isset(self::TYPE_ALIASES[$bu]) && in_array($au, self::TYPE_ALIASES[$bu], true)) {
            return true;
        }

        return false;
    }

    private function functionalOrderType(string $type): string
    {
        $type = mb_strtoupper($type);

        if (in_array($type, ['PROFIT-LIMIT', 'PROFIT-MARKET'], true) || str_starts_with($type, 'TAKE_PROFIT')) {
            return 'PROFIT';
        }

        if ($type === 'STOP-LIMIT' || $type === 'STOP-MARKET' || str_starts_with($type, 'STOP_')) {
            return 'STOP';
        }

        return $type;
    }

    /**
     * @param  array<string, mixed>  $pos
     */
    private function normalizeDirection(array $pos): string
    {
        if (! empty($pos['positionSide']) && in_array(mb_strtoupper((string) $pos['positionSide']), ['LONG', 'SHORT'], true)) {
            return mb_strtoupper((string) $pos['positionSide']);
        }
        if (isset($pos['side'])) {
            // Bitget one-way mode emits `positionSide=BOTH` and copies
            // the raw `holdSide` ('long' / 'short') into `side`. Pre-fix,
            // this branch treated anything other than 'BUY' as SHORT —
            // so a Bitget one-way LONG (`side='long'`) was misclassified
            // as SHORT, and the drift comparator would report DB-only /
            // exchange-only on every Bitget LONG position.
            $side = mb_strtoupper((string) $pos['side']);

            if ($side === 'LONG' || $side === 'BUY') {
                return 'LONG';
            }

            if ($side === 'SHORT' || $side === 'SELL') {
                return 'SHORT';
            }
        }
        // Mirror the multi-exchange quantity precedence used by the
        // filter at line 124 + exchangePositionData() — Binance uses
        // positionAmt, Bybit uses size, KuCoin uses currentQty/contracts,
        // Bitget uses total. Pre-fix, normalizeDirection only read the
        // first two, so a SHORT position with negative `contracts` /
        // `currentQty` / `total` defaulted to LONG via the `'0'`
        // fallback's `>= '0'` check.
        $qty = (string) ($pos['positionAmt']
            ?? $pos['size']
            ?? $pos['contracts']
            ?? $pos['total']
            ?? $pos['currentQty']
            ?? '0');

        return Math::gte($qty, '0') ? 'LONG' : 'SHORT';
    }

    /**
     * @param  array<string, mixed>  $order
     */
    private function inferOrderDirection(array $order): ?string
    {
        if (! empty($order['positionSide']) && in_array(mb_strtoupper((string) $order['positionSide']), ['LONG', 'SHORT'], true)) {
            return mb_strtoupper((string) $order['positionSide']);
        }

        return null;
    }

    private function isClosePositionType(?string $type): bool
    {
        if ($type === null) {
            return false;
        }

        return in_array(mb_strtoupper($type), self::CLOSE_POSITION_TYPES, true);
    }

    private function trim(string $n): string
    {
        if (str_contains($n, '.')) {
            $n = mb_rtrim(mb_rtrim($n, '0'), '.');
        }

        return $n === '' || $n === '-' ? '0' : $n;
    }
}
