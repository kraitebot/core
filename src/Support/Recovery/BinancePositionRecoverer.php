<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Recovery;

use Illuminate\Support\Str;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\ValueObjects\ApiProperties;
use Throwable;

/**
 * BinancePositionRecoverer
 *
 * Recovers a Binance USDM-Futures account by reading the live exchange
 * state via three endpoints:
 *
 *   - GET /fapi/v3/positionRisk (via apiQueryPositions)  → open positions
 *   - GET /fapi/v1/openOrders   + /fapi/v1/openAlgoOrders → pending orders
 *   - GET /fapi/v1/userTrades                              → fill history
 *
 * Algo orders (STOP-MARKET on the futures algo lane) are merged into the
 * pending list because the bot persists them as is_algo=true rows under
 * the same Position. Their identifier is `algoId`, distinct from the
 * regular `orderId` namespace, so dedupe still keys cleanly.
 */
final class BinancePositionRecoverer extends AbstractPositionRecoverer
{
    /**
     * Per-symbol leverage map populated lazily on first
     * {@see fetchOpenPositions()} call. Binance's /fapi/v3/positionRisk
     * stopped returning leverage, so we side-fetch /fapi/v1/symbolConfig
     * once and merge the result onto each position before passing it
     * down to the base reconciler.
     *
     * @var array<string, int>|null
     */
    private ?array $leverageBySymbol = null;

    /**
     * Per-symbol order-type map (orderId → LIMIT/MARKET/STOP_MARKET/...).
     * Populated lazily per symbol via /fapi/v1/allOrders so historical
     * fills coming back from /fapi/v1/userTrades can be classified
     * correctly. Without this every fill looks like a MARKET and the
     * OrderObserver's "one active MARKET per position" guard rejects
     * the second insert.
     *
     * @var array<string, array<string, string>>
     */
    private array $orderTypeBySymbolOrderId = [];

    /**
     * Open positions on the exchange. The mapper-resolved shape matches
     * the abstract base's expectations; an extra _openingPrice is set
     * so the base's openingPrice resolution stays simple (Binance's
     * `entryPrice` doesn't always equal `breakEvenPrice`, but for an
     * already-open position the breakeven IS the recovery anchor).
     *
     * Leverage is merged in here from a one-shot symbolConfig query —
     * positionRisk no longer carries it.
     */
    protected function fetchOpenPositions(): array
    {
        $response = $this->account->apiQueryPositions();
        $positions = $response->result ?? [];

        $leverages = $this->leverageMap();

        $open = [];
        foreach ($positions as $p) {
            $amt = (string) ($p['positionAmt'] ?? '0');
            if ((float) $amt === 0.0) {
                continue;
            }

            $p['_openingPrice'] = (string) ($p['breakEvenPrice'] ?? $p['entryPrice'] ?? '0');

            // Merge leverage from symbolConfig if positionRisk didn't
            // include one. Both numeric and string forms are tolerated
            // — the base recoverer casts.
            if (empty($p['leverage']) && isset($leverages[$p['symbol'] ?? ''])) {
                $p['leverage'] = $leverages[$p['symbol']];
            }

            $open[] = $p;
        }

        return $open;
    }

    /**
     * Lazy fetch of the leverage map keyed by symbol. Called once on
     * the first position-fetch; cached on the instance for the rest of
     * the run.
     *
     * @return array<string, int>
     */
    protected function leverageMap(): array
    {
        if ($this->leverageBySymbol !== null) {
            return $this->leverageBySymbol;
        }

        try {
            $response = $this->account->apiQuerySymbolConfig();
            $rows = $response->result ?? [];
        } catch (Throwable $e) {
            $this->report->warning("Binance symbolConfig fetch failed for account #{$this->account->id}: {$e->getMessage()}");
            $rows = [];
        }

        $map = [];
        foreach ($rows as $symbol => $row) {
            $key = is_string($symbol) ? $symbol : ($row['symbol'] ?? null);
            if ($key === null) {
                continue;
            }
            $lev = (int) ($row['leverage'] ?? 0);
            if ($lev > 0) {
                $map[$key] = $lev;
            }
        }

        $this->leverageBySymbol = $map;

        return $map;
    }

    /**
     * Combine /fapi/v1/openOrders (LIMIT, PROFIT-LIMIT) with
     * /fapi/v1/openAlgoOrders (STOP-MARKET algo). Both filtered to the
     * recovered position's symbol so we only carry orders that belong
     * to this position.
     */
    protected function fetchOpenOrders(Position $position, array $exchangePosition): array
    {
        $symbol = (string) ($exchangePosition['symbol'] ?? $position->parsed_trading_pair);

        $regular = $this->fetchRegularOpenOrders($symbol);
        $algo = $this->fetchAlgoOpenOrders($symbol);

        return [...$regular, ...$algo];
    }

    /**
     * Per-symbol fill history via /fapi/v1/userTrades. Bounded by 1000
     * rows per call (Binance hard limit) — sufficient for a single
     * position lifetime in practice; deeper history requires
     * pagination which we don't need for active recovery.
     */
    protected function fetchHistoricalFills(Position $position, array $exchangePosition): array
    {
        $symbol = (string) ($exchangePosition['symbol'] ?? $position->parsed_trading_pair);
        $direction = $this->normaliseDirection($exchangePosition);
        $positionAmt = (float) self::absDelta('0', (string) $this->absQuantity($exchangePosition));

        // /fapi/v1/userTrades returns FILL-level rows without the
        // originating order type. Cross-reference /fapi/v1/allOrders
        // to map orderId → type so each historical Order row gets
        // labelled correctly (LIMIT vs MARKET vs STOP_MARKET). Without
        // this, every fill looks like a MARKET — the OrderObserver's
        // single-active-MARKET guard rejects the second insert and the
        // whole position reconcile rolls back.
        $orderTypes = $this->orderTypeMap($symbol);

        // Binance caps userTrades to a 7-day window per call when
        // startTime is set. We slide the window backwards in 7-day
        // chunks (up to 5 chunks = 35 days) until either the position's
        // entry qty is fully accounted for OR we exhaust the lookback.
        // This is the only way to recover positions opened more than
        // a week ago — the default endpoint behaviour omits older
        // fills and the entry weighted-avg comes back wrong.
        $body = $this->fetchUserTradesAcrossWindows($symbol, $direction, $positionAmt);

        if ($body === []) {
            return [];
        }

        // Hedge-mode positions carry positionSide on every fill —
        // we want only the fills that touched THIS slot (LONG/SHORT
        // disambiguation). One-way fills carry positionSide=BOTH and
        // get included unconditionally; later windowing isolates them.
        $body = $this->filterFillsByPositionSide($body, $direction);

        // **Walk fills newest-first, accumulating signed qty until we
        // arrive back at the position's current open size.** That set
        // is exactly the fills that built the open slot we're now
        // recovering — anything older belongs to PRIOR closed positions
        // on the same symbol and must not be attributed here.
        //
        // Why this works: if the slot is currently SHORT 924, we walk
        // back through fills (close-fills shrink the imaginary running
        // size, entry-fills grow it). The boundary is wherever running
        // size hits 0 working backwards — meaning the position was
        // opened at that point and everything before is unrelated.
        $body = $this->windowFillsToCurrentPosition($body, $direction, $positionAmt);

        // Bucket fills by orderId. One Order row per unique exchange
        // orderId; quantity is the sum of partial fills. Avg price is
        // weighted by qty so resulting price reflects what Binance
        // reports as the order's avg fill price.
        $byOrder = [];
        foreach ($body as $fill) {
            $orderId = (string) ($fill['orderId'] ?? '');
            if ($orderId === '') {
                continue;
            }

            $qty = (string) ($fill['qty'] ?? '0');
            $price = (string) ($fill['price'] ?? '0');
            $time = (int) ($fill['time'] ?? 0);

            if (! isset($byOrder[$orderId])) {
                $byOrder[$orderId] = [
                    'orderId' => $orderId,
                    'symbol' => $symbol,
                    'side' => mb_strtoupper((string) ($fill['side'] ?? '')),
                    'positionSide' => mb_strtoupper((string) ($fill['positionSide'] ?? '')),
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

            // Look up the original Binance order type for this orderId.
            // Fall back to LIMIT — the OrderObserver allows multiple
            // active LIMITs but caps active MARKETs at one, so an
            // unknown-type fill defaulting to LIMIT keeps the recovery
            // moving when allOrders' 7-day window doesn't cover the
            // older fills. The bot doesn't act differently on FILLED
            // LIMIT vs FILLED MARKET — both are terminal-state entry
            // records.
            $exchangeType = $orderTypes[$row['orderId']] ?? 'LIMIT';
            $localType = $this->mapBinanceTypeToLocal($exchangeType);

            $orders[] = [
                'orderId' => $row['orderId'],
                'symbol' => $row['symbol'],
                'side' => $row['side'],
                'positionSide' => $row['positionSide'],
                'price' => $avgPrice,
                'origQty' => $totalQty,
                'executedQty' => $totalQty,
                'updateTime' => $row['time_max'],
                '_localType' => $localType,
                '_localStatus' => 'FILLED',
            ];
        }

        return $orders;
    }

    /**
     * Lazy fetch of orderId → type map for a symbol via /fapi/v1/allOrders.
     * Cached per-symbol on the instance so repeat lookups during the
     * same recovery run are free. Returns an empty map on failure —
     * the caller falls back to MARKET, which is the common entry case.
     *
     * @return array<string, string>
     */
    protected function orderTypeMap(string $symbol, ?int $startTimeMs = null): array
    {
        $startTimeMs ??= $this->historyLookbackStartMs();
        $cacheKey = $symbol.':'.(string) $startTimeMs;
        if (isset($this->orderTypeBySymbolOrderId[$cacheKey])) {
            return $this->orderTypeBySymbolOrderId[$cacheKey];
        }

        // Same 7-day windowing constraint as userTrades — slide
        // backwards in 7-day chunks until we cover the lookback budget.
        $perWindowMs = 7 * 86400 * 1000;
        $endTimeMs = (int) (microtime(true) * 1000);
        $stopAtMs = $startTimeMs;

        $map = [];

        while ($endTimeMs > $stopAtMs) {
            $windowStartMs = max($stopAtMs, $endTimeMs - $perWindowMs);

            $properties = new ApiProperties;
            $properties->set('relatable', $this->account);
            $properties->set('account', $this->account);
            $properties->set('options.symbol', $symbol);
            $properties->set('options.limit', '1000');
            $properties->set('options.startTime', (string) $windowStartMs);
            $properties->set('options.endTime', (string) $endTimeMs);

            try {
                $response = $this->account->withApi()->getAllOrders($properties);
                $body = json_decode((string) $response->getBody(), associative: true);
            } catch (Throwable $e) {
                $this->report->warning("Binance getAllOrders window failed for {$symbol} on account #{$this->account->id}: {$e->getMessage()}");
                break;
            }

            if (is_array($body)) {
                foreach ($body as $row) {
                    $orderId = (string) ($row['orderId'] ?? '');
                    if ($orderId === '' || isset($map[$orderId])) {
                        continue;
                    }
                    $type = mb_strtoupper((string) ($row['type'] ?? ''));
                    if ($type !== '') {
                        $map[$orderId] = $type;
                    }
                }
            }

            $endTimeMs = $windowStartMs;
        }

        $this->orderTypeBySymbolOrderId[$cacheKey] = $map;

        return $map;
    }

    /**
     * Fetch userTrades across multiple 7-day windows until the
     * position's entry qty is fully covered (running net of entry
     * minus close fills equals positionAmt going back in time) or we
     * exhaust the lookback budget.
     *
     * Why iterative: Binance's /fapi/v1/userTrades caps each call to
     * a 7-day window when startTime is set (or returns the most-recent
     * batch when no time params are sent — itself bounded). Positions
     * older than 7 days come back with missing entry fills, and the
     * recovered weighted-avg entry diverges from the exchange's
     * reported breakEvenPrice.
     *
     * Walk strategy: window 1 is now → now-7d (no startTime, default
     * endpoint behaviour returns recent batch). Each subsequent window
     * shifts back another 7 days. Stop when running net qty hits zero
     * walking back through merged fills, OR after 5 windows (~35 days).
     *
     * @return array<int, array<string, mixed>>
     */
    protected function fetchUserTradesAcrossWindows(string $symbol, string $direction, float $positionAmt): array
    {
        $windows = 5;
        $perWindowMs = 7 * 86400 * 1000;
        $endTimeMs = (int) (microtime(true) * 1000);
        $entrySide = $direction === 'SHORT' ? 'SELL' : 'BUY';

        $merged = [];
        $running = $positionAmt;
        $epsilon = 1e-9;

        for ($i = 0; $i < $windows; $i++) {
            $startTimeMs = $endTimeMs - $perWindowMs;

            $properties = new ApiProperties;
            $properties->set('relatable', $this->account);
            $properties->set('account', $this->account);
            $properties->set('options.symbol', $symbol);
            $properties->set('options.limit', '1000');
            // First window omits time bounds so we get Binance's
            // default "most recent" set — guarantees we don't miss
            // fresh fills if the wall clock is mid-second-precision
            // off. Subsequent windows shift backwards explicitly.
            if ($i > 0) {
                $properties->set('options.startTime', (string) $startTimeMs);
                $properties->set('options.endTime', (string) $endTimeMs);
            }

            try {
                $response = $this->account->withApi()->accountTrades($properties);
                $body = json_decode((string) $response->getBody(), associative: true);
            } catch (Throwable $e) {
                $this->report->warning("userTrades window fetch failed for {$symbol} on account #{$this->account->id}: {$e->getMessage()}");
                break;
            }

            if (! is_array($body) || $body === []) {
                $endTimeMs -= $perWindowMs;

                continue;
            }

            $merged = [...$merged, ...$body];

            // Estimate whether we have enough by simulating the same
            // walk over what we've collected so far. Cheap — we'll
            // re-walk authoritatively after merging is done.
            $sortedSoFar = $merged;
            usort($sortedSoFar, static fn (array $a, array $b): int => (int) ($b['time'] ?? 0) <=> (int) ($a['time'] ?? 0));
            $running = $positionAmt;
            foreach ($sortedSoFar as $fill) {
                if ($running <= $epsilon) {
                    break;
                }
                $qty = (float) ($fill['qty'] ?? 0);
                $side = mb_strtoupper((string) ($fill['side'] ?? ''));
                $running += $side === $entrySide ? -$qty : +$qty;
            }

            if ($running <= $epsilon) {
                break;
            }

            $endTimeMs -= $perWindowMs;
        }

        return $merged;
    }

    /**
     * History lookback for fill / order-type queries. 30 days back
     * covers the lifetime of every active bot position in practice
     * (the oldest open position observed during the 2026-05-01
     * disaster recovery was 9 days old). Without this lookback,
     * /userTrades only returns the most recent batch and positions
     * older than ~7 days come back partially-filled, producing entry
     * drift on the admin UI.
     *
     * Memoised on the instance so the same symbol's two calls
     * (orderTypeMap + accountTrades) share the bound.
     */
    protected function historyLookbackStartMs(): int
    {
        return (time() - 30 * 86400) * 1000;
    }

    /**
     * Resolve the position's open time (millisecond epoch) for use as
     * a lower bound on history queries. Binance returns this as
     * `updateTime` on the position-risk row; any sibling timestamp is
     * accepted as a fallback. Returns null when no timestamp can be
     * derived — caller proceeds with no time filter.
     */
    protected function resolveStartTimeMs(array $exchangePosition): ?int
    {
        $candidates = ['updateTime', 'cTime', 'createTime', 'openTime'];

        foreach ($candidates as $key) {
            $raw = $exchangePosition[$key] ?? null;
            if ($raw === null || $raw === '') {
                continue;
            }
            $ts = (int) $raw;

            // Binance always uses millisecond epochs on its V1/V3 API,
            // but a defensive check handles the rare second-epoch case
            // (older Binance betas, third-party reproxies).
            return $ts < 9_999_999_999 ? $ts * 1000 : $ts;
        }

        return null;
    }

    /**
     * Drop fills that don't belong to this position's slot. In hedge
     * mode the `positionSide` field disambiguates LONG vs SHORT and we
     * keep only fills with a matching side. In one-way mode every fill
     * carries positionSide=BOTH and gets included — the windowing pass
     * isolates the right slice.
     *
     * @param  array<int, array<string, mixed>>  $fills
     * @return array<int, array<string, mixed>>
     */
    protected function filterFillsByPositionSide(array $fills, string $direction): array
    {
        return array_values(array_filter($fills, static function (array $fill) use ($direction): bool {
            $side = mb_strtoupper((string) ($fill['positionSide'] ?? ''));
            if ($side === '' || $side === 'BOTH') {
                return true;
            }

            return $side === $direction;
        }));
    }

    /**
     * Walk fills newest-first, accumulating the signed contribution to
     * the position's open size, and stop when the running total equals
     * the current `positionAmt`. The fills returned are exactly those
     * that built the currently-open slot.
     *
     * Sign convention (entry vs close):
     *   - LONG  position: BUY = entry (+qty), SELL = close (-qty)
     *   - SHORT position: SELL = entry (+qty), BUY  = close (-qty)
     *
     * Walking backwards in time, an entry fill subtracts from the
     * imaginary running size (because going earlier in time, the slot
     * was smaller before that fill happened); a close fill adds. When
     * the running size hits 0 walking back, that's the position's
     * birth — earlier fills are from different (closed) positions.
     *
     * The Binance API returns userTrades with newest-first ordering by
     * default; we sort defensively in case of API shape change.
     *
     * @param  array<int, array<string, mixed>>  $fills
     * @return array<int, array<string, mixed>>
     */
    protected function windowFillsToCurrentPosition(array $fills, string $direction, float $positionAmt): array
    {
        if ($positionAmt <= 0.0 || $fills === []) {
            return $fills;
        }

        // Sort newest-first by `time` to make the walk deterministic
        // regardless of the API's default ordering.
        usort($fills, static fn (array $a, array $b): int => (int) ($b['time'] ?? 0) <=> (int) ($a['time'] ?? 0));

        $entrySide = $direction === 'SHORT' ? 'SELL' : 'BUY';
        $running = $positionAmt;
        $epsilon = 1e-9;
        $kept = [];

        foreach ($fills as $fill) {
            if ($running <= $epsilon) {
                break;
            }

            $kept[] = $fill;

            $qty = (float) ($fill['qty'] ?? 0);
            $side = mb_strtoupper((string) ($fill['side'] ?? ''));

            // Going backwards in time: entry fill makes the slot
            // smaller (because before that fill, the slot didn't yet
            // include this qty); close fill makes it bigger (because
            // before the close, that qty was still part of the slot).
            $running += $side === $entrySide ? -$qty : +$qty;
        }

        return array_values($kept);
    }

    /**
     * Translate a Binance API order-type string to our local Order.type
     * vocabulary. Same matrix as {@see mapOrderType()} but takes a
     * pre-extracted string so the historical-fills path can call it
     * after looking up types via the orderId map.
     */
    protected function mapBinanceTypeToLocal(string $binanceType): string
    {
        return match (mb_strtoupper($binanceType)) {
            'MARKET' => 'MARKET',
            'LIMIT' => 'LIMIT',
            'TAKE_PROFIT', 'TAKE_PROFIT_LIMIT' => 'PROFIT-LIMIT',
            'STOP_MARKET', 'STOP' => 'STOP-MARKET',
            default => $binanceType === '' ? 'MARKET' : $binanceType,
        };
    }

    /**
     * Map one exchange-shaped row to local Order columns. Handles both
     * fill-derived (isFilled=true) and pending (isFilled=false) shapes
     * with the same dispatch — fill rows carry `_localType` /
     * `_localStatus` keys; pending rows carry the live `type` /
     * `status` strings from /fapi/v1/openOrders.
     */
    protected function toLocalOrderAttributes(Position $position, array $exchangeOrder, bool $isFilled): array
    {
        $exchangeOrderId = (string) ($exchangeOrder['orderId'] ?? $exchangeOrder['algoId'] ?? '');
        $isAlgo = isset($exchangeOrder['algoId']) || ($exchangeOrder['_isAlgo'] ?? false);

        $type = $exchangeOrder['_localType'] ?? $this->mapOrderType($exchangeOrder);
        $status = $exchangeOrder['_localStatus'] ?? $this->mapOrderStatus($exchangeOrder);

        $price = (string) ($exchangeOrder['price']
            ?? $exchangeOrder['stopPrice']
            ?? $exchangeOrder['avgPrice']
            ?? '0');

        // Binance's STOP_MARKET (algo) carries `triggerPrice` in the
        // open-algo-orders response shape — fall back to that when the
        // canonical `price` field is empty.
        if ((float) $price === 0.0) {
            $price = (string) ($exchangeOrder['triggerPrice'] ?? '0');
        }

        $quantity = (string) ($exchangeOrder['origQty']
            ?? $exchangeOrder['quantity']
            ?? $exchangeOrder['executedQty']
            ?? '0');

        $openedAt = $exchangeOrder['updateTime'] ?? $exchangeOrder['time'] ?? null;
        if ($openedAt) {
            $secs = (int) $openedAt;
            $secs = $secs > 9_999_999_999 ? intdiv($secs, 1000) : $secs;
            $openedAt = date('Y-m-d H:i:s', $secs);
        }

        $filledAt = $isFilled ? $openedAt : null;

        return [
            'position_id' => $position->id,
            'exchange_order_id' => $exchangeOrderId,
            'client_order_id' => (string) ($exchangeOrder['clientOrderId'] ?? $exchangeOrder['_clientId'] ?? Str::uuid()->toString()),
            'type' => $type,
            'status' => $status,
            'side' => mb_strtoupper((string) ($exchangeOrder['side'] ?? 'BUY')),
            'position_side' => mb_strtoupper((string) ($exchangeOrder['positionSide'] ?? $position->direction)),
            'is_algo' => $isAlgo,
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
     * Pull /fapi/v1/openOrders for the symbol. Annotated with `_isAlgo`
     * = false so the upsert layer can distinguish from the algo-lane
     * payload below.
     */
    protected function fetchRegularOpenOrders(string $symbol): array
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $this->account);
        $properties->set('account', $this->account);
        $properties->set('options.symbol', $symbol);

        try {
            $response = $this->account->withApi()->getCurrentOpenOrders($properties);
            $body = json_decode((string) $response->getBody(), associative: true);
        } catch (Throwable $e) {
            $this->report->warning("getCurrentOpenOrders failed for {$symbol} on account #{$this->account->id}: {$e->getMessage()}");

            return [];
        }

        if (! is_array($body)) {
            return [];
        }

        return array_map(static function (array $o): array {
            $o['_isAlgo'] = false;

            return $o;
        }, $body);
    }

    /**
     * Pull /fapi/v1/openAlgoOrders for the symbol. Annotated with
     * `_isAlgo` = true; the algoId becomes the local exchange_order_id.
     */
    protected function fetchAlgoOpenOrders(string $symbol): array
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $this->account);
        $properties->set('account', $this->account);
        $properties->set('options.symbol', $symbol);

        try {
            $response = $this->account->withApi()->getAlgoOpenOrders($properties);
            $body = json_decode((string) $response->getBody(), associative: true);
        } catch (Throwable $e) {
            $this->report->warning("getAlgoOpenOrders failed for {$symbol} on account #{$this->account->id}: {$e->getMessage()}");

            return [];
        }

        // Binance's algo endpoint wraps results under `orders` in some
        // responses and returns a bare array in others — handle both.
        $list = is_array($body['orders'] ?? null) ? $body['orders'] : (is_array($body) ? $body : []);

        return array_map(static function (array $o): array {
            $o['_isAlgo'] = true;
            // Normalise to the same key shape as regular orders so the
            // upsert can read them uniformly.
            $o['orderId'] ??= $o['algoId'] ?? null;

            return $o;
        }, $list);
    }

    /**
     * Map Binance's `type` / `algoType` field to the local order-type
     * vocabulary. Recovery only deals with the four types the bot
     * actively manages (LIMIT / PROFIT-LIMIT / STOP-MARKET / MARKET).
     *
     * Two Binance shapes need extra handling here:
     *
     *  1. Take-profit on the regular orderbook is sent as
     *     `type=LIMIT` plus `reduceOnly=true` — there's no native
     *     `TAKE_PROFIT_LIMIT` for our flow. Distinguished by the side
     *     being OPPOSITE the position direction (BUY closes a SHORT,
     *     SELL closes a LONG) AND reduceOnly being set.
     *
     *  2. Algo SL comes back from `/fapi/v1/openAlgoOrders` with
     *     `type=CONDITIONAL` (the algo wrapper) and `algoType` further
     *     specifying the trigger style. Both collapse to STOP-MARKET
     *     in our local taxonomy because the bot only uses
     *     stop-market-style algos today.
     */
    protected function mapOrderType(array $exchangeOrder): string
    {
        $raw = mb_strtoupper((string) ($exchangeOrder['type'] ?? $exchangeOrder['algoType'] ?? 'LIMIT'));

        // TP shortcut: regular LIMIT + reduceOnly = closing-intent LIMIT,
        // which the bot models as PROFIT-LIMIT.
        if ($raw === 'LIMIT' && $this->isTakeProfitLimit($exchangeOrder)) {
            return 'PROFIT-LIMIT';
        }

        return match ($raw) {
            'STOP_MARKET', 'STOP-MARKET', 'CONDITIONAL' => 'STOP-MARKET',
            'TAKE_PROFIT', 'TAKE_PROFIT_LIMIT', 'PROFIT-LIMIT' => 'PROFIT-LIMIT',
            'MARKET' => 'MARKET',
            default => $raw === '' ? 'LIMIT' : $raw,
        };
    }

    /**
     * Heuristic: a regular open-orders LIMIT row is a take-profit when
     * `reduceOnly=true`. Binance does not flag PROFIT-LIMIT on the type
     * field; the only signal is reduceOnly. Defensive: also check
     * closePosition because some legacy payloads use that flag instead.
     */
    protected function isTakeProfitLimit(array $exchangeOrder): bool
    {
        $reduceOnly = $exchangeOrder['reduceOnly'] ?? false;
        $closePosition = $exchangeOrder['closePosition'] ?? false;

        return (bool) $reduceOnly || (bool) $closePosition;
    }

    /**
     * Map Binance's `status` vocabulary to our local set. Unknown
     * statuses surface as-is so we can spot drift between Binance's
     * responses and our assumptions during testing.
     */
    protected function mapOrderStatus(array $exchangeOrder): string
    {
        $raw = mb_strtoupper((string) ($exchangeOrder['status'] ?? 'NEW'));

        return match ($raw) {
            'NEW' => 'NEW',
            'PARTIALLY_FILLED' => 'PARTIALLY_FILLED',
            'FILLED' => 'FILLED',
            'CANCELED', 'CANCELLED' => 'CANCELLED',
            'EXPIRED' => 'CANCELLED',
            default => $raw,
        };
    }
}
