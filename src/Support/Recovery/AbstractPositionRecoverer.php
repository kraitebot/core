<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Recovery;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\Math;
use Throwable;

/**
 * AbstractPositionRecoverer
 *
 * Reconstructs the local DB mirror of an account's currently-open
 * positions and their related orders by querying the exchange directly
 * — no Steps, no orchestrator. Used for disaster recovery and ongoing
 * gap-filling.
 *
 * Each concrete recoverer overrides three responsibilities:
 *
 *   1. {@see fetchOpenPositions()}   — list exchange positions with size > 0
 *   2. {@see fetchOpenOrders()}      — pending LIMIT/PROFIT/STOP-MARKET
 *                                       orders attached to the position
 *   3. {@see fetchHistoricalFills()} — FILLED orders since position open
 *                                       (entry MARKET, partial LIMITs)
 *
 * The base class owns the orchestration: per-position transaction,
 * idempotency lookups, percentage back-calculation, report accumulation.
 *
 * Idempotency contract:
 *   - Position match: account + exchange_symbol + status IN openedStatuses(),
 *     with the exchange direction required to match the local direction.
 *   - Opposite LONG/SHORT positions on one symbol are rejected. Exchange
 *     hedge mode changes the API contract; it does not change Kraite's
 *     one-position-per-symbol trading policy.
 *   - Order match: exchange_order_id (one-shot dedupe per row).
 *   Already-present rows are left untouched. Only the missing pieces
 *   are inserted, so subsequent runs are no-ops.
 */
abstract class AbstractPositionRecoverer
{
    /**
     * Account-wide open orders pre-fetched ONCE by the caller (the fleet
     * recovery job) so the concrete recoverer can filter them per symbol
     * in memory instead of a per-symbol API call. Null = per-symbol
     * fallback (the original inline behaviour).
     *
     * @var array<int, array<string, mixed>>|null
     */
    protected ?array $batchedOpenOrders = null;

    /** @var array<int, array<string, mixed>>|null Account-wide algo orders, same batching contract as {@see}. */
    protected ?array $batchedAlgoOrders = null;

    /**
     * Disaster-recovery readiness flag. Concrete recoverers that have NOT
     * been verified against a live exchange account set {@see $untested} to
     * true to gate themselves behind `--allow-untested-exchange`. Default
     * false so verified exchanges (Binance/Bitget) run without ceremony.
     *
     * Backed by a property rather than an overridden method on purpose:
     * Pint's `final_public_method_for_abstract_class` rule forces this
     * public accessor to be `final`, and a `final` method a subclass tries
     * to override fatals ("Cannot override final method") the instant that
     * recoverer class loads. A property the subclass redeclares sidesteps
     * the rule entirely while keeping the accessor final and stable.
     */
    protected bool $untested = false;

    public function __construct(
        protected Account $account,
        protected RecoveryReport $report,
        protected ?string $tokenFilter = null,
    ) {}

    // ===== Per-exchange contract — concrete recoverers MUST implement =====

    /** @return array<int, array<string, mixed>> Open positions on the exchange (positionAmt > 0). */
    abstract protected function fetchOpenPositions(): array;

    /** @return array<int, array<string, mixed>> Currently-pending orders for this position. */
    abstract protected function fetchOpenOrders(Position $position, array $exchangePosition): array;

    /** @return array<int, array<string, mixed>> Historical FILLED orders since the position opened. */
    abstract protected function fetchHistoricalFills(Position $position, array $exchangePosition): array;

    /**
     * Map one exchange-shaped order/fill row to local-Order attributes.
     *
     * @param  array<string, mixed>  $exchangeOrder
     * @return array<string, mixed>
     */
    abstract protected function toLocalOrderAttributes(Position $position, array $exchangeOrder, bool $isFilled): array;

    /**
     * Inject the account-wide open + algo order batches. When set, the
     * concrete recoverer filters these per symbol instead of hitting the
     * exchange once per position — collapsing 2N per-position order calls
     * into 2 per account. Fluent so callers can chain before run().
     */
    final public function withBatchedOrders(?array $openOrders, ?array $algoOrders): static
    {
        $this->batchedOpenOrders = $openOrders;
        $this->batchedAlgoOrders = $algoOrders;

        return $this;
    }

    final public function isUntested(): bool
    {
        return $this->untested;
    }

    /**
     * Top-level entry. Walks the open-positions list and reconciles each
     * one. Wraps every position in its own transaction so a single bad
     * row doesn't roll back the whole account.
     */
    final public function run(): void
    {
        $positions = $this->fetchOpenPositions();
        $count = count($positions);

        $this->report->line("  → Found {$count} open position(s) on exchange");

        if ($this->tokenFilter !== null) {
            $before = $count;
            $positions = array_filter($positions, fn (array $p): bool => mb_strtoupper((string) $p['symbol']) === mb_strtoupper($this->tokenFilter));
            $count = count($positions);
            $this->report->line("  → Filtered by --token={$this->tokenFilter}: {$count} of {$before} retained");
        }

        $positions = $this->withoutConflictingSymbolSides($positions);

        foreach ($positions as $exchangePosition) {
            try {
                DB::transaction(function () use ($exchangePosition): void {
                    $this->reconcilePosition($exchangePosition);
                });
            } catch (Throwable $e) {
                $symbol = $exchangePosition['symbol'] ?? 'unknown';
                $this->report->warning(
                    "Account #{$this->account->id} — position {$symbol} reconcile failed: {$e->getMessage()}"
                );
                $this->report->line("    ✗ {$symbol}: {$e->getMessage()}");
            }
        }
    }

    /**
     * Absolute delta between two numeric strings — `|a - b|` — kept here
     * because Math has no abs() helper and we need a single negation-free
     * difference for the percentage calc above.
     */
    protected static function absDelta(string $a, string $b): string
    {
        $diff = Math::sub($a, $b, 8);

        return Math::lt($diff, '0') ? Math::mul($diff, '-1', 8) : $diff;
    }

    /**
     * Reconcile one exchange-side position with the local DB. Resolves
     * the local exchange_symbol, finds-or-creates the Position row, then
     * delegates to order reconciliation.
     */
    protected function reconcilePosition(array $exchangePosition): void
    {
        $tradingPair = (string) $exchangePosition['symbol'];
        $direction = $this->normaliseDirection($exchangePosition);

        $exchangeSymbol = ExchangeSymbol::query()
            ->where('api_system_id', $this->account->api_system_id)
            ->where('asset', $tradingPair)
            ->when(
                $this->account->trading_quote !== null,
                fn ($query) => $query->where('quote', $this->account->trading_quote)
            )
            ->first();

        $exchangeSymbol ??= ExchangeSymbol::query()
            ->where('api_system_id', $this->account->api_system_id)
            ->whereHas('symbol', fn ($q) => $q->where(function ($q2) use ($tradingPair): void {
                // Match by parsed pair (BTCUSDT / BTC-USDT) — the
                // accessor on ExchangeSymbol composes token+quote.
                $q2->whereRaw('CONCAT(token, quote) = ?', [$tradingPair])
                    ->orWhereRaw("CONCAT(token, '-', quote) = ?", [$tradingPair]);
            }))
            ->first();

        // Fallback to the parsed_trading_pair-style match via ExchangeSymbol attrs.
        if ($exchangeSymbol === null) {
            $exchangeSymbol = ExchangeSymbol::query()
                ->where('api_system_id', $this->account->api_system_id)
                ->whereRaw('CONCAT(token, quote) = ?', [$tradingPair])
                ->first();
        }

        if ($exchangeSymbol === null) {
            $this->report->positionsSkipped++;
            $this->report->warning("Symbol {$tradingPair} not found in local exchange_symbols for account #{$this->account->id} — skipped");
            $this->report->line("    ⚠ {$tradingPair} {$direction}: symbol not in local exchange_symbols, skipped");

            return;
        }

        $existingPosition = Position::query()
            ->where('account_id', $this->account->id)
            ->where('exchange_symbol_id', $exchangeSymbol->id)
            ->whereIn('status', (new Position)->openedStatuses())
            ->first();

        if ($existingPosition !== null) {
            if ($existingPosition->direction !== $direction) {
                $this->report->positionsSkipped++;
                $this->report->warning(
                    "Account #{$this->account->id} — {$tradingPair} already has an open {$existingPosition->direction} position; opposite {$direction} exchange side was not recovered."
                );
                $this->report->line("    ✗ {$tradingPair} {$direction}: open {$existingPosition->direction} position #{$existingPosition->id} already owns the symbol");

                return;
            }

            $this->report->line("    • {$tradingPair} {$direction}: position #{$existingPosition->id} already exists (status={$existingPosition->status}) — checking orders");
            $this->reconcileOrders($existingPosition, $exchangePosition);
            $this->report->positionsUpdated++;

            return;
        }

        $position = $this->createPositionFromExchange($exchangePosition, $exchangeSymbol, $direction);

        $this->report->positionsCreated++;
        $this->report->line(sprintf(
            '    %s %s %s: created position #%s (qty=%s, entry=%s, leverage=%s)',
            $this->report->dryRun ? '[dry-run]' : '✓',
            $tradingPair,
            $direction,
            $position->id ?? 'pending',
            $position->quantity,
            $position->opening_price,
            $position->leverage ?? '?',
        ));

        $this->reconcileOrders($position, $exchangePosition);

        // Back-calc TP/SL percentages once orders are in place. Done
        // after order reconciliation so the TP/SL prices are visible
        // through the relation and we don't re-fetch.
        $this->backCalculatePercentages($position);
    }

    /**
     * Create a fresh Position row mirroring the exchange's view. Only the
     * columns the exchange can authoritatively answer for are set here;
     * strategy-side metadata (indicators_*, multipliers — those that
     * exist as columns) are filled by {@see populateStrategyMetadata}
     * if the recoverer overrides it.
     *
     * `opening_price` is seeded from the exchange's current weighted
     * average (`breakEvenPrice` / `openPriceAvg`) as a placeholder; the
     * real anchor — the FIRST entry fill's price — is back-filled by
     * {@see backCalculatePercentages()} once the historical orders are
     * recovered. We can't set it here because the historical fills
     * haven't been fetched yet at this point in the flow.
     */
    protected function createPositionFromExchange(
        array $exchangePosition,
        ExchangeSymbol $exchangeSymbol,
        string $direction,
    ): Position {
        $quantity = $this->absQuantity($exchangePosition);
        $opening = (string) ($exchangePosition['_openingPrice'] ?? $exchangePosition['breakEvenPrice'] ?? $exchangePosition['openPriceAvg'] ?? '0');
        $leverage = (int) ($exchangePosition['leverage'] ?? 0) ?: null;
        $openedAt = $this->resolveOpenedAt($exchangePosition);

        $margin = ($leverage !== null && Math::gt($opening, '0') && Math::gt($quantity, '0'))
            ? Math::div(Math::mul($opening, $quantity), (string) $leverage, 8)
            : null;

        $attributes = [
            'account_id' => $this->account->id,
            'exchange_symbol_id' => $exchangeSymbol->id,
            'parsed_trading_pair' => $exchangePosition['symbol'] ?? null,
            'direction' => $direction,
            'status' => 'active',
            'opened_at' => $openedAt,
            'opening_price' => $opening,
            'quantity' => $quantity,
            'leverage' => $leverage,
            'margin' => $margin,
            'indicators_timeframe' => $this->latestIndicatorsTimeframe(),
            'indicators_values' => $this->latestIndicatorsValues($exchangeSymbol),
        ];

        // total_limit_orders gets set in reconcileOrders once we know how
        // many LIMITs the exchange has attached. Leaving it null here
        // would violate the schema if the column is non-nullable, so we
        // seed with a sensible default that the order pass overwrites.
        $attributes['total_limit_orders'] = (int) ($this->account->total_limit_orders ?? 4);

        return Position::create($attributes);
    }

    /**
     * Pull both the OPEN orders (pending LIMIT/PROFIT/STOP-MARKET) and
     * the historical FILLED orders for this position, then upsert each
     * one keyed by exchange_order_id.
     */
    protected function reconcileOrders(Position $position, array $exchangePosition): void
    {
        $openOrders = $this->fetchOpenOrders($position, $exchangePosition);
        $fills = $this->fetchHistoricalFills($position, $exchangePosition);

        $newCount = 0;

        foreach ($openOrders as $orderData) {
            if ($this->upsertOrder($position, $orderData, isFilled: false)) {
                $newCount++;
            }
        }

        foreach ($fills as $fillData) {
            if ($this->upsertOrder($position, $fillData, isFilled: true)) {
                $newCount++;
            }
        }

        $this->report->ordersCreated += $newCount;

        // Refresh total_limit_orders to reflect what we now know — the
        // count of LIMIT orders associated with the position (FILLED +
        // open). Keeps WAP / laddering math consistent with reality.
        $limitCount = $position->orders()->where('type', 'LIMIT')->count();
        if ($limitCount > 0 && $limitCount !== (int) $position->total_limit_orders) {
            $position->update(['total_limit_orders' => $limitCount]);
        }
    }

    /**
     * Insert one order row if exchange_order_id isn't already present;
     * skip otherwise. Returns true on insert. The recoverer's hook
     * {@see toLocalOrderAttributes()} does the per-exchange shape mapping.
     */
    protected function upsertOrder(Position $position, array $exchangeOrder, bool $isFilled): bool
    {
        $attributes = $this->toLocalOrderAttributes($position, $exchangeOrder, $isFilled);
        $exchangeOrderId = (string) ($attributes['exchange_order_id'] ?? '');

        if ($exchangeOrderId === '') {
            $this->report->ordersSkipped++;

            return false;
        }

        // Scope the idempotency check to THIS position. Globally keying
        // by `exchange_order_id` alone is unsafe — exchange order ids
        // are not guaranteed unique across accounts, exchanges, or
        // algo-vs-regular id spaces. Recovery for account B previously
        // could skip a real order because account A already had the
        // same id. Bitget UTA is the exception within one position: its
        // combined full-position TP/SL strategy has one exchange ID but two
        // local logical rows, distinguished by type.
        $exists = Order::query()
            ->where('position_id', $position->id)
            ->where('exchange_order_id', $exchangeOrderId)
            ->where('type', $attributes['type'])
            ->exists();

        if ($exists) {
            $this->report->ordersSkipped++;

            return false;
        }

        $order = Order::create($attributes);

        $this->report->line(sprintf(
            '      %s order #%s: type=%s status=%s side=%s qty=%s price=%s',
            $this->report->dryRun ? '[dry-run]' : '✓',
            $order->id ?? 'pending',
            $attributes['type'] ?? '?',
            $attributes['status'] ?? '?',
            $attributes['side'] ?? '?',
            $attributes['quantity'] ?? '?',
            $attributes['price'] ?? '?',
        ));

        return true;
    }

    /**
     * Reconstruct strategy anchors after orders are recovered.
     *
     * Why this is more than just back-calculating two percentages:
     *
     *   - `opening_price` lives forever as the ORIGINAL first-entry
     *     price. The bot writes it once at MARKET fill and never
     *     touches it again — even after WAP shifts the breakeven.
     *     During recovery we initially seeded it with the exchange's
     *     CURRENT weighted average (breakEvenPrice) because the
     *     historical fills hadn't been fetched yet; now we know the
     *     actual oldest entry fill, so we overwrite with the real
     *     anchor.
     *
     *   - `first_profit_price` is the ORIGINAL TP target, computed
     *     from `opening_price ± profit% × opening_price` at the time
     *     the bot first placed the TP. After WAP fires, the live TP
     *     order's `price` field moves to track the new breakeven, but
     *     `first_profit_price` stays put — it's the lifecycle-bar
     *     anchor in the dashboard. We derive it the same way the bot
     *     would: from the original entry price + profit_percentage.
     *
     *   - `profit_percentage` is back-calc'd from the CURRENT TP price
     *     vs CURRENT weighted-avg breakeven (not the original entry).
     *     The bot keeps profit_percentage CONSTANT across WAP cycles
     *     and recomputes the TP price each time; the percentage
     *     measured on current state therefore equals the original
     *     stored value.
     *
     *   - `was_waped` / `waped_at` are tagged true when more than one
     *     FILLED entry-side order exists — every LIMIT fill after the
     *     first triggers WAP in the live flow, so multiple entry fills
     *     means WAP has already run.
     *
     * If the position has no TP order on the exchange (rare — usually
     * a degraded mid-open position), the percentages and
     * first_profit_price stay null. opening_price is still corrected
     * to the first entry fill if any exist; otherwise the seeded
     * breakeven stands.
     */
    protected function backCalculatePercentages(Position $position): void
    {
        $position->refresh()->load('orders');

        $direction = mb_strtoupper((string) $position->direction);
        $isLong = $direction === 'LONG';
        $entrySide = $isLong ? 'BUY' : 'SELL';

        // Walk FILLED entry-side orders chronologically; oldest is the
        // original MARKET (or first LIMIT) that opened the slot.
        $entryFills = $position->orders
            ->where('status', 'FILLED')
            ->where('side', $entrySide)
            ->sortBy('opened_at')
            ->values();

        $updates = [];

        // Anchor #1: opening_price = first entry fill's price.
        // Overwrites the placeholder we seeded with the current
        // breakeven during createPositionFromExchange — the recovered
        // history now lets us pin the real first-fill anchor.
        if ($entryFills->isNotEmpty()) {
            $firstFill = $entryFills->first();
            $firstFillPrice = (string) $firstFill->price;

            if (Math::gt($firstFillPrice, '0')) {
                $updates['opening_price'] = $firstFillPrice;
            }

            // WAP tracking: more than one entry-side fill means a
            // LIMIT (or another MARKET) filled after the original
            // entry, which would have triggered the live WAP flow.
            // Recovery can't replay the WAP runs, but it CAN flag the
            // position so the dashboard / drift logic knows the
            // current TP is a post-WAP value.
            if ($entryFills->count() > 1) {
                $updates['was_waped'] = true;
                $updates['waped_at'] = $entryFills->last()->opened_at;
            }
        }

        // Now do the percentage back-calc. We need the CURRENT
        // weighted-avg entry price (= exchange's breakEven) for the
        // profit_percentage math because that's what the bot computed
        // its still-valid percentage against. Fall back to the
        // position's seeded breakeven if no fills are present.
        $currentBreakeven = $this->computeWeightedAvgEntry($entryFills);
        if (! Math::gt($currentBreakeven, '0')) {
            $currentBreakeven = (string) $position->opening_price;
        }

        $tp = $position->orders
            ->whereIn('type', ['PROFIT-LIMIT', 'PROFIT-MARKET'])
            ->whereIn('status', ['NEW', 'PARTIALLY_FILLED'])
            ->first();

        $sl = $position->orders
            ->where('type', 'STOP-MARKET')
            ->whereIn('status', ['NEW', 'PARTIALLY_FILLED'])
            ->first();

        if ($tp !== null && Math::gt((string) $tp->price, '0') && Math::gt($currentBreakeven, '0')) {
            $diff = self::absDelta($currentBreakeven, (string) $tp->price);
            $profitPct = Math::mul(Math::div($diff, $currentBreakeven, 8), '100', 3);
            // DECIMAL columns accept the BCMath string directly —
            // skipping the float cast keeps full precision.
            $updates['profit_percentage'] = $profitPct;

            // Anchor #2: first_profit_price computed from the original
            // entry + the percentage. For LONG, TP is above entry; for
            // SHORT, below. Mirrors the first PlaceProfitOrderJob run.
            $anchor = $updates['opening_price'] ?? (string) $position->opening_price;
            if (Math::gt($anchor, '0')) {
                $delta = Math::mul(Math::div($profitPct, '100', 8), $anchor, 8);
                $updates['first_profit_price'] = $isLong
                    ? Math::add($anchor, $delta, 8)
                    : Math::sub($anchor, $delta, 8);
            }
        }

        if ($sl !== null && Math::gt((string) $sl->price, '0') && Math::gt($currentBreakeven, '0')) {
            $diff = self::absDelta($currentBreakeven, (string) $sl->price);
            $updates['stop_market_percentage'] = Math::mul(Math::div($diff, $currentBreakeven, 8), '100', 2);
        }

        if ($updates !== []) {
            $position->update($updates);

            $this->report->line(sprintf(
                '      → anchored opening=%s first_profit=%s profit_pct=%s stop_pct=%s waped=%s',
                $updates['opening_price'] ?? (string) $position->opening_price,
                $updates['first_profit_price'] ?? 'n/a',
                isset($updates['profit_percentage']) ? $updates['profit_percentage'].'%' : 'n/a',
                isset($updates['stop_market_percentage']) ? $updates['stop_market_percentage'].'%' : 'n/a',
                isset($updates['was_waped']) && $updates['was_waped'] ? 'true' : 'false',
            ));
        }
    }

    /**
     * Weighted-average price across a collection of FILLED entry-side
     * orders. Used as the proxy for the exchange's current
     * `breakEvenPrice` when reconstructing percentages — the exchange
     * already reports it the same way, so the local recomputation
     * matches within float precision.
     *
     * @param  Collection<int, Order>  $entryFills
     */
    protected function computeWeightedAvgEntry($entryFills): string
    {
        $totalQty = '0';
        $weightedCost = '0';

        foreach ($entryFills as $order) {
            $qty = (string) $order->quantity;
            $price = (string) $order->price;
            if (! Math::gt($qty, '0') || ! Math::gt($price, '0')) {
                continue;
            }

            $totalQty = Math::add($totalQty, $qty, 18);
            $weightedCost = Math::add($weightedCost, Math::mul($qty, $price, 18), 18);
        }

        if (! Math::gt($totalQty, '0')) {
            return '0';
        }

        return Math::div($weightedCost, $totalQty, 18);
    }

    /** Last computed indicators timeframe — null in fresh DBs is fine. */
    protected function latestIndicatorsTimeframe(): ?string
    {
        return $this->account->tradeConfiguration?->indicators_timeframe
            ?? null;
    }

    /**
     * Latest indicator snapshot for the symbol (JSON-friendly array).
     * Recoverers can override to pull a real snapshot — the abstract
     * default is null and the bot tolerates that on already-open
     * positions because indicators are decision-driving only at OPEN
     * time.
     */
    protected function latestIndicatorsValues(ExchangeSymbol $exchangeSymbol): ?array
    {
        unset($exchangeSymbol);

        return null;
    }

    protected function absQuantity(array $exchangePosition): string
    {
        $raw = (string) ($exchangePosition['positionAmt']
            ?? $exchangePosition['size']
            ?? $exchangePosition['total']
            ?? '0');

        return Math::lt($raw, '0') ? Math::mul($raw, '-1') : $raw;
    }

    protected function resolveOpenedAt(array $exchangePosition): ?string
    {
        $candidates = ['updateTime', 'cTime', 'openedAt', 'createTime'];

        foreach ($candidates as $key) {
            if (! empty($exchangePosition[$key])) {
                $ts = (int) $exchangePosition[$key];
                // Normalise ms → s if necessary (millisecond timestamps
                // since 2001 always exceed 32-bit-second's max).
                $seconds = $ts > 9_999_999_999 ? intdiv($ts, 1000) : $ts;

                return date('Y-m-d H:i:s', $seconds);
            }
        }

        return null;
    }

    /**
     * Direction normalisation. Hedge accounts emit LONG/SHORT directly;
     * one-way accounts emit BOTH and need the sign of positionAmt to
     * disambiguate.
     */
    protected function normaliseDirection(array $exchangePosition): string
    {
        $side = mb_strtoupper((string) ($exchangePosition['positionSide'] ?? $exchangePosition['holdSide'] ?? ''));

        if ($side === 'LONG' || $side === 'SHORT') {
            return $side;
        }

        // BOTH or empty → infer from sign of positionAmt.
        $raw = (string) ($exchangePosition['positionAmt'] ?? '0');

        return Math::lt($raw, '0') ? 'SHORT' : 'LONG';
    }

    /**
     * Reject an externally-created hedged symbol as one invalid unit. This
     * prevents array order from deciding which side is partially recovered.
     * Valid hedge-mode accounts still recover normally when only one side of
     * each symbol is open.
     *
     * @param  array<int, array<string, mixed>>  $positions
     * @return array<int, array<string, mixed>>
     */
    private function withoutConflictingSymbolSides(array $positions): array
    {
        $directionsBySymbol = [];
        $rowsBySymbol = [];

        foreach ($positions as $position) {
            $symbol = mb_strtoupper(mb_trim((string) ($position['symbol'] ?? '')));
            if ($symbol === '') {
                continue;
            }

            $directionsBySymbol[$symbol][$this->normaliseDirection($position)] = true;
            $rowsBySymbol[$symbol] = ($rowsBySymbol[$symbol] ?? 0) + 1;
        }

        $conflictingSymbols = [];

        foreach ($directionsBySymbol as $symbol => $directions) {
            if (count($directions) < 2) {
                continue;
            }

            $conflictingSymbols[$symbol] = true;
            $skipped = $rowsBySymbol[$symbol];
            $this->report->positionsSkipped += $skipped;
            $this->report->warning(
                "Account #{$this->account->id} — symbol {$symbol} has simultaneous LONG and SHORT exchange positions; Kraite does not hedge symbols, so neither side was recovered."
            );
            $this->report->line("    ✗ {$symbol}: simultaneous LONG and SHORT exchange positions rejected ({$skipped} sides skipped)");
        }

        if ($conflictingSymbols === []) {
            return array_values($positions);
        }

        return array_values(array_filter(
            $positions,
            static fn (array $position): bool => ! isset($conflictingSymbols[mb_strtoupper(mb_trim((string) ($position['symbol'] ?? '')))]),
        ));
    }
}
