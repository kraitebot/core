<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\Order\Bitget;

use Kraite\Core\Abstracts\BaseApiableJob;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\Math;
use Kraite\Core\Support\Proxies\ApiDataMapperProxy;
use RuntimeException;
use Throwable;

/**
 * ModifyAlgoOrderJob (Atomic) — Bitget
 *
 * Restores a drifted Bitget position-level TP/SL order back to its
 * `reference_price` by re-calling `place-pos-tpsl` with both legs.
 *
 * Bitget's `pos_profit` / `pos_loss` orders are attached to the position
 * and cannot be modified by the per-order endpoints used elsewhere:
 *  - `cancel-plan-order` returns a silent no-op (`successList:[],
 *    failureList:[]`) for them — they're not standalone plan orders.
 *  - `modify-tpsl-order` rejects them (HTTP 400 / code 400172 across
 *    every field-name combination tried).
 *
 * `place-pos-tpsl` IS overwriting on the existing TP/SL atomically and
 * preserves the existing plan-order IDs (verified live 2026-04-26 against
 * FETUSDT pos 421). So the correction is: read the sibling leg's current
 * trigger (the one not drifted), re-call place-pos-tpsl with the drifted
 * leg's `reference_price` and the sibling's unchanged price.
 *
 * Flow:
 *  1. startOrFail: position active, order is_algo + drifted, has reference_price
 *  2. computeApiable: re-place both TP+SL via place-pos-tpsl
 *  3. doubleCheck: trivially true — overwrite is server-side atomic and
 *     the subsequent SyncPositionOrdersJob will re-pull the live trigger
 *  4. complete: write reference_price back into local price column so the
 *     OrderObserver does not re-fire the correction loop
 */
final class ModifyAlgoOrderJob extends BaseApiableJob
{
    public Position $position;

    public Order $order;

    public function __construct(int $positionId, int $orderId)
    {
        $this->position = Position::findOrFail($positionId);
        $this->order = Order::findOrFail($orderId);
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

    public function startOrFail(): bool
    {
        if (! in_array($this->position->status, $this->position->activeStatuses(), true)) {
            return false;
        }

        if ($this->order->position_id !== $this->position->id) {
            return false;
        }

        if (! $this->order->is_algo) {
            return false;
        }

        if (! in_array($this->order->type, ['PROFIT-LIMIT', 'STOP-MARKET'], true)) {
            return false;
        }

        if ($this->order->reference_price === null) {
            return false;
        }

        if (Math::equal((string) $this->order->price, (string) $this->order->reference_price)) {
            return false;
        }

        return true;
    }

    public function computeApiable()
    {
        $account = $this->position->account;
        $referencePrice = (string) $this->order->reference_price;

        $sibling = $this->findSiblingAlgoOrder();
        if ($sibling === null) {
            throw new RuntimeException(sprintf(
                'Cannot modify Bitget TP/SL for order %d: sibling %s leg not found on position %d',
                $this->order->id,
                $this->order->type === 'STOP-MARKET' ? 'TP' : 'SL',
                $this->position->id,
            ));
        }

        $thisIsStopLoss = $this->order->type === 'STOP-MARKET';
        $tpPrice = $thisIsStopLoss ? (string) $sibling->price : $referencePrice;
        $slPrice = $thisIsStopLoss ? $referencePrice : (string) $sibling->price;

        $mapper = new ApiDataMapperProxy($account->apiSystem->canonical);
        $properties = $mapper->preparePlacePosTpslProperties($this->position, $tpPrice, $slPrice);
        $properties->set('account', $account);

        $response = $account->withApi()->placePosTpsl($properties);
        $result = $mapper->resolvePlacePosTpslResponse($response);

        if (! ($result['success'] ?? false)) {
            throw new RuntimeException('Failed to overwrite Bitget TP/SL: '.json_encode($result['_raw'] ?? []));
        }

        return [
            'position_id' => $this->position->id,
            'order_id' => $this->order->id,
            'type' => $this->order->type,
            'old_price' => (string) $this->order->price,
            'new_price' => $referencePrice,
            'sibling_order_id' => $sibling->id,
            'sibling_unchanged_price' => (string) $sibling->price,
            'message' => 'Bitget TP/SL trigger restored to reference price',
        ];
    }

    /**
     * Find the sibling algo leg on this position (TP <-> SL pair). Required
     * because Bitget's `place-pos-tpsl` overwrites BOTH legs atomically;
     * we need the unchanged leg's current price to feed back unchanged.
     *
     * MUST select the newest LIVE leg (activeOnExchange + latest). Recreate
     * workflows retain historical CANCELLED/EXPIRED rows, and an unfiltered
     * first() picked the oldest row — feeding place-pos-tpsl an obsolete
     * trigger silently rewrote the healthy live leg's protection. Ambiguity
     * (two live legs of one type) is structurally prevented by the
     * OrderObserver's per-type active-order limit.
     */
    public function findSiblingAlgoOrder(): ?Order
    {
        $siblingType = $this->order->type === 'STOP-MARKET' ? 'PROFIT-LIMIT' : 'STOP-MARKET';

        return $this->position->orders()
            ->where('type', $siblingType)
            ->where('is_algo', true)
            ->where('id', '!=', $this->order->id)
            ->activeOnExchange()
            ->latest('id')
            ->first();
    }

    /**
     * Modify is server-side atomic on Bitget — there is no separate
     * verification endpoint. The next `SyncPositionOrdersJob` will pull
     * the live trigger and confirm the change.
     */
    public function doubleCheck(): bool
    {
        return true;
    }

    /**
     * Snap the local `price` column back to `reference_price` so the
     * `OrderObserver` no longer detects drift on the next sync pass and
     * stops re-dispatching `PrepareOrderCorrectionJob` every minute.
     */
    public function complete(): void
    {
        if ($this->order->reference_price === null) {
            return;
        }

        $this->order->updateSaving([
            'price' => $this->order->reference_price,
        ]);
    }

    public function resolveException(Throwable $e): void
    {
        $this->position->updateSaving([
            'error_message' => 'Bitget TP/SL modify failed: '.$e->getMessage(),
        ]);
    }
}
