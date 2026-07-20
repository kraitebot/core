<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\Order\Bitget;

use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\DB;
use Kraite\Core\Abstracts\BaseApiableJob;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Enums\BitgetAccountMode;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ExchangeSymbolPrice;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\Proxies\ApiDataMapperProxy;
use Kraite\Core\Support\TpSlResolver;
use Kraite\Core\Trading\Kraite;
use RuntimeException;
use StepDispatcher\Models\Step;
use Throwable;

/**
 * PlacePositionTpslJob (Atomic) - Bitget
 *
 * Places full-position protection on Bitget. Classic uses one combined
 * position TP/SL call; Unified creates independent full-position strategies.
 *
 * Flow:
 * 1. startOrFail(): Verify position has required data
 * 2. computeApiable():
 *    - Calculate TP price via Kraite::calculateProfitOrder()
 *    - Calculate SL price via Kraite::calculateStopLossOrder()
 *    - Persist durable local TP/SL identities
 *    - Place protection through the account-mode-specific API
 *    - Persist returned exchange order IDs, querying position only as fallback
 * 3. doubleCheck(): Query position, verify both IDs present
 * 4. complete(): Set reference_* fields on both orders, set first_profit_price on position
 */
final class PlacePositionTpslJob extends BaseApiableJob
{
    public Position $position;

    public ?Order $profitOrder = null;

    public ?Order $stopLossOrder = null;

    public ?string $tpPrice = null;

    public ?string $slPrice = null;

    public function __construct(
        int $positionId,
        ?int $profitOrderId = null,
        ?int $stopLossOrderId = null,
    ) {
        $this->position = Position::findOrFail($positionId);
        $this->profitOrder = $this->findProtectionOrder($profitOrderId, 'PROFIT-LIMIT');
        $this->stopLossOrder = $this->findProtectionOrder($stopLossOrderId, 'STOP-MARKET');
        $this->tpPrice = $this->profitOrder?->price;
        $this->slPrice = $this->stopLossOrder?->price;
    }

    public function assignExceptionHandler(): void
    {
        $this->exceptionHandler = BaseExceptionHandler::make(
            $this->position->account->apiSystem->canonical
        )->withAccount($this->position->account);
    }

    public function relatable()
    {
        return $this->position;
    }

    /**
     * Verify position is ready for TP/SL placement.
     */
    public function startOrFail(): bool
    {
        // Position must be in an active status (opening, active, syncing, etc.)
        if (! in_array($this->position->status, $this->position->activeStatuses(), true)) {
            return false;
        }

        // Must have opening_price (market order filled)
        if ($this->position->opening_price === null) {
            return false;
        }

        // Must have quantity
        if ($this->position->quantity === null) {
            return false;
        }

        // Must have profit_percentage for TP calculation
        if ($this->position->profit_percentage === null) {
            return false;
        }

        // Must have a resolvable anchor price for the SL calculation. In
        // martingale mode the deepest LIMIT rung is the worst-case fill we
        // hedge against; in simple-trade mode (total_limit_orders === 0)
        // the MARKET fill is the only fill, so `opening_price` is the
        // canonical anchor instead.
        if ($this->resolveAnchorPrice() === null) {
            return false;
        }

        // SL percentage must resolve to a non-null value. Snapshot is the
        // canonical source; resolveStopLossPercentage() falls back to the
        // account default for positions opened before the snapshot column
        // existed (or for half-baked deploys where PrepareJob ran old code).
        if ($this->resolveStopLossPercentage() === null) {
            return false;
        }

        return true;
    }

    /**
     * Resolve the anchor price for the stop-loss calculation.
     *
     *   total_limit_orders === 0  → opening_price (MARKET fill, simple-trade mode)
     *   total_limit_orders >= 1   → lastLimitOrder()->price (deepest DCA rung)
     *
     * Mirrors the Binance PlaceStopLossOrderJob's anchor resolution. Bitget
     * places TP+SL atomically through `place-pos-tpsl`, so the SL anchor
     * computed here drives the same shared placement call.
     */
    public function resolveAnchorPrice(): ?string
    {
        if ((int) $this->position->total_limit_orders === 0) {
            $opening = $this->position->opening_price;

            if ($opening === null || $opening === '') {
                return null;
            }

            return (string) $opening;
        }

        $lastLimitOrder = $this->position->lastLimitOrder();

        if ($lastLimitOrder === null) {
            return null;
        }

        return (string) $lastLimitOrder->price;
    }

    /**
     * Resolve the SL percentage. Mirror of the Binance PlaceStopLossOrderJob
     * fallback — snapshot first, then live resolve through TpSlResolver.
     * Bitget pairs TP+SL atomically through `place-pos-tpsl`, so the SL
     * value here drives the same shared placement call.
     */
    public function resolveStopLossPercentage(): ?string
    {
        $snapshot = $this->position->stop_market_percentage;
        if ($snapshot !== null && $snapshot !== '') {
            return (string) $snapshot;
        }

        $account = $this->position->account;
        if ($account->stop_market_initial_percentage === null) {
            return null;
        }

        return TpSlResolver::resolve(
            symbolValue: $this->position->exchangeSymbol?->stop_market_percentage,
            accountOverride: (bool) $account->override_sl,
            accountValue: (string) $account->stop_market_initial_percentage,
        );
    }

    public function computeApiable()
    {
        $exchangeSymbol = $this->position->exchangeSymbol;
        $direction = $this->position->direction;
        $account = $this->position->account;

        // Side is opposite to close position
        $side = $direction === 'LONG' ? 'SELL' : 'BUY';

        // Fetch fresh mark price so TP re-anchors if price already passed
        $canonical = $account->apiSystem->canonical;
        $markMapper = new ApiDataMapperProxy($canonical);
        $markProperties = $markMapper->prepareQueryMarkPriceProperties($exchangeSymbol);
        $markResponse = Account::admin($canonical)->withApi()->getMarkPrice($markProperties);
        $markPrice = $markMapper->resolveQueryMarkPriceResponse($markResponse);

        if (! $markPrice || ! is_numeric($markPrice)) {
            throw new RuntimeException("Failed to fetch mark price for {$exchangeSymbol->parsed_trading_pair}");
        }

        // Cutover (2026-05-04): mark-price storage moved to the
        // exchange_symbol_prices sidecar so the daemon's hot
        // write path no longer contends with parent-table writers.
        // This Bitget-side single-row update writes through the
        // same sidecar so the accessor on ExchangeSymbol returns
        // the freshly-fetched value on subsequent reads.
        ExchangeSymbolPrice::updateOrCreate(
            ['exchange_symbol_id' => $exchangeSymbol->id],
            ['mark_price' => $markPrice, 'mark_price_synced_at' => now()],
        );

        // Bust the in-memory relationship cache so the accessor
        // re-reads the sidecar on the next access in this request.
        $exchangeSymbol->unsetRelation('priceRow');

        // Calculate take-profit price (re-anchor TP if mark price already passed it)
        $profitData = Kraite::calculateProfitOrder(
            direction: $direction,
            referencePrice: $this->position->opening_price,
            profitPercent: $this->position->profit_percentage,
            currentQty: $this->position->quantity,
            exchangeSymbol: $exchangeSymbol,
            recalculateOnLowerThanMarkPrice: true,
        );
        $this->tpPrice = $profitData['price'];

        // Anchor source depends on mode: martingale uses the deepest LIMIT
        // rung's scheduled price (worst-case projected fill); simple-trade
        // uses the MARKET fill price (the only fill in that mode).
        $anchorPrice = $this->resolveAnchorPrice();

        $stopLossData = Kraite::calculateStopLossOrder(
            direction: $direction,
            anchorPrice: $anchorPrice,
            stopPercent: $this->resolveStopLossPercentage(),
            currentQty: $this->position->quantity,
            exchangeSymbol: $exchangeSymbol,
        );
        $this->slPrice = $stopLossData['price'];

        // Persist both local identities before the mutating API call. The
        // dispatcher reconstructs jobs from Step.arguments on every retry;
        // without these IDs it loses the rows and cannot prove that Bitget
        // already accepted the protection request.
        $this->persistProtectionOrders($side);

        if ($account->resolveBitgetAccountMode() === BitgetAccountMode::Unified) {
            return $this->placeUnifiedProtectionOrders(
                anchorPrice: (string) $anchorPrice,
                side: $side,
            );
        }

        // Place combined TP/SL on exchange via position endpoint
        $mapper = new ApiDataMapperProxy($account->apiSystem->canonical);
        $properties = $mapper->preparePlacePosTpslProperties(
            $this->position,
            $this->tpPrice,
            $this->slPrice,
            $this->profitOrder->client_order_id,
            $this->stopLossOrder->client_order_id,
        );
        $properties->set('account', $account);

        /** @var Response $response */
        $response = $account->withApi()->placePosTpsl($properties);
        $result = $mapper->resolvePlacePosTpslResponse($response);

        if (! ($result['success'] ?? false)) {
            throw new RuntimeException('Failed to place position TP/SL: '.json_encode($result['_raw'] ?? []));
        }

        $ordersByClientOrderId = is_array($result['ordersByClientOid'] ?? null)
            ? $result['ordersByClientOid']
            : [];
        $takeProfitId = $ordersByClientOrderId[$this->profitOrder->client_order_id] ?? null;
        $stopLossId = $ordersByClientOrderId[$this->stopLossOrder->client_order_id] ?? null;

        // Compatibility fallback for older Bitget response shapes. The normal
        // path uses the IDs returned by place-pos-tpsl and avoids an extra
        // rate-limited positions request.
        if ($takeProfitId === null || $stopLossId === null) {
            $positionData = $this->queryPositionForTpslIds();
            $takeProfitId ??= $positionData['takeProfitId'] ?? null;
            $stopLossId ??= $positionData['stopLossId'] ?? null;
        }

        $this->profitOrder->updateSaving([
            'exchange_order_id' => $takeProfitId,
            'opened_at' => now(),
        ]);
        $this->stopLossOrder->updateSaving([
            'exchange_order_id' => $stopLossId,
            'opened_at' => now(),
        ]);

        return [
            'position_id' => $this->position->id,
            'profit_order_id' => $this->profitOrder->id,
            'stop_loss_order_id' => $this->stopLossOrder->id,
            'trading_pair' => $exchangeSymbol->parsed_trading_pair,
            'direction' => $direction,
            'side' => $side,
            'tp_price' => $this->tpPrice,
            'sl_price' => $this->slPrice,
            'anchor_price' => $anchorPrice,
            'take_profit_id' => $takeProfitId,
            'stop_loss_id' => $stopLossId,
            'message' => 'Position TP/SL placed on exchange',
        ];
    }

    /**
     * Query position to get TP/SL order IDs.
     *
     * @return array{takeProfitId: ?string, stopLossId: ?string}
     */
    public function queryPositionForTpslIds(): array
    {
        $account = $this->position->account;
        $mapper = new ApiDataMapperProxy($account->apiSystem->canonical);

        $properties = $mapper->prepareQueryPositionsProperties($account);
        $properties->set('account', $account);

        /** @var Response $response */
        $response = $account->withApi()->getPositions($properties);
        $positions = $mapper->resolveQueryPositionsResponse($response);

        // Find our position by symbol:positionSide. Hedge-mode responses
        // key by LONG/SHORT; one-way responses key by BOTH. Mirrors the segment logic on
        // CalculateWapAndModifyProfitOrderJob::buildPositionKey().
        $symbol = $this->position->exchangeSymbol->parsed_trading_pair;
        $segment = $this->position->account?->isHedgeMode()
            ? mb_strtoupper((string) $this->position->direction)
            : 'BOTH';
        $key = "{$symbol}:{$segment}";

        $positionData = $positions[$key] ?? [];

        return [
            'takeProfitId' => $positionData['takeProfitId'] ?? null,
            'stopLossId' => $positionData['stopLossId'] ?? null,
        ];
    }

    /**
     * Verify the TP/SL orders were accepted.
     *
     * Fast path: when both order rows already carry an `exchange_order_id`
     * captured during `computeApiable()`, skip the API round-trip entirely.
     * This is the 99% case under normal load and avoids the doubleCheck →
     * getPositions → 429 failure mode that wrongly killed an already-placed
     * position (THETAUSDT, 2026-04-26).
     *
     * Slow path: when Bitget eventual consistency returned a null id at
     * place time, re-query to backfill. Any failure here returns false so
     * the step framework retries the doubleCheck — rather than escaping
     * the API exception handler (which is wired only into `compute()`)
     * and crashing the step.
     */
    public function doubleCheck(): bool
    {
        if ($this->profitOrder === null || $this->stopLossOrder === null) {
            return false;
        }

        if ($this->profitOrder->exchange_order_id !== null
            && $this->stopLossOrder->exchange_order_id !== null) {
            return true;
        }

        try {
            $positionData = $this->queryPositionForTpslIds();
        } catch (Throwable) {
            return false;
        }

        $hasTpId = $positionData['takeProfitId'] !== null;
        $hasSlId = $positionData['stopLossId'] !== null;

        if ($hasTpId && $this->profitOrder->exchange_order_id === null) {
            $this->profitOrder->updateSaving([
                'exchange_order_id' => $positionData['takeProfitId'],
            ]);
        }

        if ($hasSlId && $this->stopLossOrder->exchange_order_id === null) {
            $this->stopLossOrder->updateSaving([
                'exchange_order_id' => $positionData['stopLossId'],
            ]);
        }

        return $hasTpId && $hasSlId;
    }

    /**
     * Set reference data, first_profit_price, and emit audit log entries.
     *
     * Mirrors the Binance equivalents (PlaceProfitOrderJob, PlaceStopLossOrderJob)
     * by firing one appLog per leg so the audit trail is symmetric across exchanges.
     */
    public function complete(): void
    {
        // Set reference data for profit order
        if ($this->profitOrder !== null) {
            $this->profitOrder->updateSaving([
                'reference_price' => $this->profitOrder->price,
                'reference_quantity' => $this->profitOrder->quantity,
                'reference_status' => $this->profitOrder->status,
            ]);

            $this->position->appLog(
                event: 'profit_order_placed',
                message: "Profit order placed at \${$this->profitOrder->price}",
                metadata: [
                    'order_id' => $this->profitOrder->id,
                    'price' => $this->profitOrder->price,
                    'quantity' => $this->profitOrder->quantity,
                ]
            );
        }

        // Set reference data for stop-loss order
        if ($this->stopLossOrder !== null) {
            $this->stopLossOrder->updateSaving([
                'reference_price' => $this->stopLossOrder->price,
                'reference_quantity' => $this->stopLossOrder->quantity,
                'reference_status' => $this->stopLossOrder->status,
            ]);

            $this->position->appLog(
                event: 'stop_loss_placed',
                message: "Stop-loss order placed at \${$this->stopLossOrder->price}",
                metadata: [
                    'order_id' => $this->stopLossOrder->id,
                    'price' => $this->stopLossOrder->price,
                    'quantity' => $this->stopLossOrder->quantity,
                ]
            );
        }

        // Store first_profit_price on position for reference
        if ($this->tpPrice !== null) {
            $this->position->updateSaving([
                'first_profit_price' => $this->tpPrice,
            ]);
        }
    }

    /**
     * Handle exceptions during TP/SL placement.
     */
    public function resolveException(Throwable $e): void
    {
        $this->position->updateSaving([
            'error_message' => 'Position TP/SL failed: '.$e->getMessage(),
        ]);
    }

    private function findProtectionOrder(?int $orderId, string $type): ?Order
    {
        if ($orderId === null) {
            return null;
        }

        return Order::query()
            ->where('position_id', $this->position->id)
            ->where('type', $type)
            ->findOrFail($orderId);
    }

    private function persistProtectionOrders(string $side): void
    {
        DB::transaction(function () use ($side): void {
            $attributes = [
                'status' => 'NEW',
                'side' => $side,
                'position_side' => $this->position->direction,
                'quantity' => $this->position->quantity,
                'is_algo' => true,
            ];

            if ($this->profitOrder === null) {
                $this->profitOrder = Order::create([
                    ...$attributes,
                    'position_id' => $this->position->id,
                    'type' => 'PROFIT-LIMIT',
                    'price' => $this->tpPrice,
                ]);
            } else {
                $this->profitOrder->updateSaving([
                    ...$attributes,
                    'price' => $this->tpPrice,
                ]);
            }

            if ($this->stopLossOrder === null) {
                $this->stopLossOrder = Order::create([
                    ...$attributes,
                    'position_id' => $this->position->id,
                    'type' => 'STOP-MARKET',
                    'price' => $this->slPrice,
                ]);
            } else {
                $this->stopLossOrder->updateSaving([
                    ...$attributes,
                    'price' => $this->slPrice,
                ]);
            }

            if (! isset($this->step)) {
                return;
            }

            /** @var Step $step */
            $step = Step::query()->whereKey($this->step->id)->lockForUpdate()->firstOrFail();
            $arguments = is_array($step->arguments) ? $step->arguments : [];
            $arguments['profitOrderId'] = $this->profitOrder->id;
            $arguments['stopLossOrderId'] = $this->stopLossOrder->id;
            $step->update(['arguments' => $arguments]);
            $this->step->setAttribute('arguments', $arguments);
        });
    }

    /**
     * UTA represents each full-position TP/SL leg as an independent strategy
     * order. Keeping two exchange IDs preserves independent sync, correction,
     * cancellation, and recreation semantics in the local order ledger.
     *
     * @return array<string, mixed>
     */
    private function placeUnifiedProtectionOrders(string $anchorPrice, string $side): array
    {
        if ($this->profitOrder === null || $this->stopLossOrder === null) {
            throw new RuntimeException('Bitget UTA protection orders were not persisted before placement.');
        }

        if ($this->profitOrder->exchange_order_id === null || $this->profitOrder->exchange_order_id === '') {
            $profitResponse = $this->profitOrder->apiPlaceTpslOrder();
            if (! ($profitResponse->result['success'] ?? false)) {
                throw new RuntimeException('Failed to place Bitget UTA take-profit strategy order.');
            }
        }

        if ($this->stopLossOrder->exchange_order_id === null || $this->stopLossOrder->exchange_order_id === '') {
            $stopLossResponse = $this->stopLossOrder->apiPlaceTpslOrder();
            if (! ($stopLossResponse->result['success'] ?? false)) {
                throw new RuntimeException('Failed to place Bitget UTA stop-loss strategy order.');
            }
        }

        $takeProfitId = $this->profitOrder->fresh()->exchange_order_id;
        $stopLossId = $this->stopLossOrder->fresh()->exchange_order_id;

        if ($takeProfitId === null || $stopLossId === null) {
            throw new RuntimeException('Bitget UTA did not return both protection strategy order IDs.');
        }

        return [
            'position_id' => $this->position->id,
            'profit_order_id' => $this->profitOrder->id,
            'stop_loss_order_id' => $this->stopLossOrder->id,
            'trading_pair' => $this->position->exchangeSymbol->parsed_trading_pair,
            'direction' => $this->position->direction,
            'side' => $side,
            'tp_price' => $this->tpPrice,
            'sl_price' => $this->slPrice,
            'anchor_price' => $anchorPrice,
            'take_profit_id' => $takeProfitId,
            'stop_loss_id' => $stopLossId,
            'message' => 'Position TP/SL placed as independent Bitget UTA strategy orders',
        ];
    }
}
