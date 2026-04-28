<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\Order;

use Kraite\Core\Abstracts\BaseApiableJob;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Throwable;

/**
 * CancelSingleAlgoOrderJob (Atomic)
 *
 * Cancels a single algo order (STOP-MARKET, etc.) on the exchange.
 * Used when correcting a modified algo order (cancel + recreate workflow).
 *
 * This job:
 * 1. Verifies the order is an active algo order
 * 2. Calls apiCancel() which routes to exchange-specific cancel endpoint
 * 3. Updates local status to CANCELLED
 *
 * Note: reference_status should be pre-set to 'CANCELLED' by the calling job
 * to prevent OrderObserver from triggering replacement workflows.
 */
final class CancelSingleAlgoOrderJob extends BaseApiableJob
{
    public Position $position;

    public Order $order;

    /**
     * Set when apiCancel fails with an "order already gone" signal that
     * the exchange-specific exception handler classifies as ignorable
     * (Binance -2011 "Unknown order sent" is the canonical example).
     * The exchange has nothing left to cancel — we treat it as success
     * and reconcile DB state. doubleCheck() reads this flag to skip
     * apiSync (which would throw the same not-found error) and accept
     * the DB's CANCELLED state as the truth.
     */
    private bool $idempotentlyResolved = false;

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

    public function relatable(): Position
    {
        return $this->position;
    }

    /**
     * Verify order can be cancelled.
     *
     * Two valid call sites:
     *  - Active workflow (modify-correction): position is in activeStatuses().
     *  - Orphan-cleanup (drift spotter): position is in nonActiveStatuses()
     *    and still has open orders the original cleanup left behind.
     *
     * Both paths target the same operation — issue a cancel on the
     * exchange — so the guard accepts either "open" or "non-active"
     * states. Anything mid-flight (closing/cancelling) is rejected
     * because the dispatcher is mid-write and a competing cancel could
     * race the active workflow.
     */
    public function startOrFail(): bool
    {
        $allowedStatuses = array_merge(
            $this->position->activeStatuses(),
            $this->position->nonActiveStatuses(),
        );

        if (! in_array($this->position->status, $allowedStatuses, true)) {
            return false;
        }

        // Order must belong to this position
        if ($this->order->position_id !== $this->position->id) {
            return false;
        }

        // Order must be an algo order
        if (! $this->order->is_algo) {
            return false;
        }

        // Order must be active (NEW or PARTIALLY_FILLED)
        if (! in_array($this->order->status, ['NEW', 'PARTIALLY_FILLED'], true)) {
            return false;
        }

        // Ghost guard. Exchange-specific cancel mappers (Binance algo,
        // Bitget plan, Bybit cancel) all require the exchange-side id
        // (algo_id / orderId) to build the request — when our DB row
        // never made it to the exchange (placement failed, observer
        // wrote NEW but apiPlace threw), exchange_order_id stays null.
        // Without this guard the job reaches the mapper, fails its
        // input validation, and returns Failed every cycle. Cleaner to
        // skip up front: nothing to cancel on the exchange anyway.
        if ($this->order->exchange_order_id === null || $this->order->exchange_order_id === '') {
            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function computeApiable(): array
    {
        try {
            $apiResponse = $this->order->apiCancel();
        } catch (Throwable $e) {
            // The exchange may have already forgotten the order — common
            // for stale orphans the spotter is sweeping (Binance returns
            // -2011 "Unknown order sent" for a cancel against a missing
            // order). Per-exchange exception handlers classify these
            // codes as ignorable; when they do, this is an idempotent
            // success: nothing left on the exchange to cancel, just
            // reconcile our DB to match reality.
            if ($this->exceptionHandler->ignoreException($e)) {
                $this->order->updateSaving(['status' => 'CANCELLED']);
                $this->idempotentlyResolved = true;

                return [
                    'position_id' => $this->position->id,
                    'order_id' => $this->order->id,
                    'type' => $this->order->type,
                    'exchange_order_id' => $this->order->exchange_order_id,
                    'message' => 'Order already gone on exchange; DB reconciled to CANCELLED.',
                    'idempotent' => true,
                ];
            }

            throw $e;
        }

        // Update local status
        $this->order->updateSaving(['status' => 'CANCELLED']);

        return [
            'position_id' => $this->position->id,
            'order_id' => $this->order->id,
            'type' => $this->order->type,
            'exchange_order_id' => $this->order->exchange_order_id,
            'api_result' => $apiResponse->result,
            'message' => 'Algo order cancelled',
        ];
    }

    /**
     * Verify the order was cancelled.
     *
     * For BitGet position-level TPSL (pos_profit/pos_loss), cancel-plan-order
     * doesn't work because they're attached to the position. In this case,
     * we skip verification since the order will remain on exchange until
     * the position closes.
     */
    public function doubleCheck(): bool
    {
        // computeApiable's idempotent branch already discovered the order
        // is gone server-side and reconciled the DB to CANCELLED. Calling
        // apiSync here would throw the same not-found error and turn a
        // legitimate idempotent success into a verification failure. The
        // DB row is the truth post-reconciliation; accept it.
        if ($this->idempotentlyResolved) {
            return $this->order->status === 'CANCELLED';
        }

        // Sync order to get current status from exchange
        $apiResponse = $this->order->apiSync();
        $this->order->refresh();

        // BitGet position-level TPSL cannot be cancelled via cancel-plan-order.
        // Detect this by checking the _isPositionTpsl flag in the sync response.
        // If so, revert local status since the order is still active on exchange.
        if (($apiResponse->result['_isPositionTpsl'] ?? false) && $this->order->status === 'NEW') {
            // Order is still active - this is expected for position TPSL
            // Revert reference_status to match actual status to prevent further drift
            $this->order->updateSaving([
                'reference_status' => $this->order->status,
                'reference_quantity' => $this->order->quantity,
            ]);

            // Return true to complete the job - the "cancel" is acknowledged as not applicable
            return true;
        }

        // Order should be CANCELLED
        return $this->order->status === 'CANCELLED';
    }

    /**
     * Handle exceptions during cancel.
     */
    public function resolveException(Throwable $e): void
    {
        $this->position->updateSaving([
            'error_message' => 'Algo order cancel failed: '.$e->getMessage(),
        ]);
    }
}
