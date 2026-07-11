<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\Order;

use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\Proxies\JobProxy;
use Kraite\Core\Trading\Kraite;
use StepDispatcher\Models\Step;

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

        // 2 + 3. Create the Order rows AND their placement steps in ONE
        // atomic, idempotent build. Orders and steps must live or die
        // together: a step-insert failure with the Order rows already
        // committed leaves phantom NEW orders with no placement step and
        // no exchange counterpart — the prior per-rung swallow shipped
        // exactly that, reported success, and the resolve-exception path
        // its own comment promised could never fire because the exception
        // never escaped. Now a mid-build failure rolls back the whole
        // ladder and reaches the exception handler: transient errors
        // retry into a clean rebuild (populated-block guard prevents a
        // second ladder), permanent ones fail the step so the opening
        // chain's CancelPosition resolver actually runs.
        //
        // Self-elect to parent only when the ladder is non-empty —
        // otherwise we'd leave a parent step whose child_block_uuid
        // points at an empty block, which the dispatcher treats as a
        // never-completing zombie.
        if (count($ladder) > 0) {
            $this->buildChildChainOnce(function (string $blockUuid) use ($ladder, $side, $direction, $resolver): void {
                // Observer silently rejects excess orders (returns false
                // from creating()), so filter removes blocked creations.
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

                collect($this->limitOrders)
                    ->each(function (Order $order, int $rungIndex) use ($resolver, $blockUuid): void {
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
                    });
            });
        }

        $totalCreated = count($this->limitOrders);

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
