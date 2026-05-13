<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\Order;

use Illuminate\Support\Facades\Log;
use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\Proxies\JobProxy;
use Kraite\Core\Trading\Kraite;
use StepDispatcher\Models\Step;
use Throwable;

/**
 * DispatchLimitOrdersJob (Orchestrator)
 *
 * Orchestrator job that:
 * 1. Calculates the limit ladder using Kraite algorithm
 * 2. Creates Order records in the database
 * 3. Creates N parallel steps to place those orders on the exchange
 *
 * Preconditions:
 * - position.status = 'opening'
 * - Market order already filled (position has quantity and opening_price)
 */
final class DispatchLimitOrdersJob extends BaseQueueableJob
{
    public Position $position;

    /** @var array<int, Order> */
    public array $limitOrders = [];

    public function __construct(int $positionId)
    {
        $this->position = Position::findOrFail($positionId);
    }

    public function relatable(): Position
    {
        return $this->position;
    }

    /**
     * Verify position is ready for limit orders.
     */
    public function startOrFail(): bool
    {
        // Position must be in an active status (opening, active, syncing, etc.)
        if (! in_array($this->position->status, $this->position->activeStatuses(), true)) {
            return false;
        }

        // Market order must have filled (position has quantity and opening_price)
        if ($this->position->quantity === null || $this->position->opening_price === null) {
            return false;
        }

        return true;
    }

    public function compute()
    {
        $exchangeSymbol = $this->position->exchangeSymbol;
        $direction = $this->position->direction;
        $totalLimitOrders = $this->position->total_limit_orders ?? 4;

        // Use position's opening_price as reference for ladder calculation
        $referencePrice = $this->position->opening_price;

        // Use position's quantity (from market order) as base for ladder quantities
        $marketOrderQty = $this->position->quantity;

        // Determine side from direction
        $side = $direction === 'LONG' ? 'BUY' : 'SELL';

        $resolver = JobProxy::with($this->position->account);

        // 1. Calculate ladder + meta → 2. Create Orders in database.
        // Pass withMeta=true so price_clamped + rung_dropped_zero_qty
        // warnings the math layer already collects flow back to us
        // instead of being silently discarded. The throwing min_notional
        // rejection path is logged by VerifyOrderNotionalForMarketOrderJob;
        // this surfaces the milder boundary-compression signals on the
        // success path so operators can spot stale symbol bounds before
        // they degrade into hard rejections.
        $ladderPayload = Kraite::calculateLimitOrdersData(
            totalLimitOrders: $totalLimitOrders,
            direction: $direction,
            referencePrice: $referencePrice,
            marketOrderQty: $marketOrderQty,
            exchangeSymbol: $exchangeSymbol,
            limitQuantityMultipliers: $exchangeSymbol->limit_quantity_multipliers,
            withMeta: true,
        );

        $ladder = $ladderPayload['ladder'];
        $ladderWarnings = $ladderPayload['__meta']['warnings'] ?? [];

        // Persist ladder warnings BEFORE any Step::create runs so a
        // downstream dispatch failure cannot eat the symbol-health
        // signal. Empty-warnings positions (the steady state) skip
        // the log entirely — only abnormal ladder calcs leave a trail.
        if ($ladderWarnings !== []) {
            $this->position->appLog(
                event: 'ladder_warnings_observed',
                message: sprintf(
                    'Ladder calc emitted %d warning(s) — likely symbol-bounds compression on %s',
                    count($ladderWarnings),
                    $exchangeSymbol->parsed_trading_pair ?? (string) $exchangeSymbol->token,
                ),
                metadata: [
                    'warnings' => $ladderWarnings,
                    'reference_price' => $referencePrice,
                    'total_limit_orders_expected' => $totalLimitOrders,
                ],
            );
        }

        // Observer silently rejects excess orders (returns false from creating()),
        // so filter removes any nulls from blocked creations
        $this->limitOrders = collect($ladder)
            ->map(function (array $rung) use ($side, $direction): Order {
                return Order::create([
                    'position_id' => $this->position->id,
                    'type' => 'LIMIT',
                    'status' => 'NEW',
                    'side' => $side,
                    'position_side' => $direction,
                    'price' => $rung['price'],
                    'quantity' => $rung['quantity'],
                ]);
            })
            ->filter()
            ->values()
            ->all();

        // 3. Create Steps to place orders on exchange (sequential to allow cancellation on failure)
        // Self-elect to parent only when there are children to spawn — otherwise
        // we'd leave a parent step with a child_block_uuid pointing at an empty
        // block, which the dispatcher treats as a never-completing zombie.
        $totalCreated = count($this->limitOrders);

        if ($totalCreated > 0) {
            $blockUuid = $this->step->child_block_uuid ?? $this->step->makeItAParent();
            $rungsDispatched = 0;
            $rungsFailed = 0;

            collect($this->limitOrders)
                ->each(function (Order $order, int $rungIndex) use ($resolver, $blockUuid, &$rungsDispatched, &$rungsFailed): void {
                    // Per-rung try/catch — a transient Step::create
                    // failure on rung 2 must not abort rungs 3..N. The
                    // ladder is already in DB as Order rows; the
                    // PlaceLimitOrderJob steps are the only piece that
                    // could fail here. Logging the failure and
                    // continuing produces a partial-but-known ladder
                    // state the lifecycle's resolve-exception path can
                    // recover from rather than a half-dispatched
                    // surprise.
                    try {
                        Step::create([
                            'class' => $resolver->resolve(PlaceLimitOrderJob::class),
                            'queue' => 'orders',
                            'relatable_type' => Order::class,
                            'relatable_id' => $order->id,
                            'arguments' => [
                                'orderId' => $order->id,
                                'rungIndex' => $rungIndex + 1,
                            ],
                            'block_uuid' => $blockUuid,
                            'index' => $rungIndex + 1,
                            'workflow_id' => null,
                        ]);
                        $rungsDispatched++;
                    } catch (Throwable $e) {
                        $rungsFailed++;
                        Log::channel('jobs')->error('[DISPATCH-LIMITS] per-rung dispatch threw — continuing', [
                            'order_id' => $order->id,
                            'rung_index' => $rungIndex + 1,
                            'exception' => $e::class,
                            'message' => $e->getMessage(),
                        ]);
                    }
                });
        }

        $this->position->appLog(
            event: 'limit_orders_dispatched',
            message: "Dispatched {$totalCreated} DCA limit orders",
            metadata: [
                'total_orders' => $totalCreated,
                'reference_price' => $referencePrice,
            ]
        );

        return [
            'position_id' => $this->position->id,
            'total_limit_orders' => $totalCreated,
            'reference_price' => $referencePrice,
            'market_qty' => $marketOrderQty,
            'orders' => collect($this->limitOrders)
                ->map(function (Order $o) {
                    return ['id' => $o->id, 'price' => $o->price, 'quantity' => $o->quantity];
                })
                ->all(),
            'message' => 'Limit orders created and dispatched',
        ];
    }
}
