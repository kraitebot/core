<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\Position;

use Illuminate\Database\Eloquent\Builder;
use Kraite\Core\Abstracts\BaseApiableJob;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Models\Position;
use Throwable;

/**
 * CancelPositionOpenOrdersJob (Atomic)
 *
 * Cancels every non-algo open order belonging to the position by
 * iterating the position's local `orders` rows and issuing one
 * exchange-side cancel per row.
 *
 * The single code path applies on every exchange (Binance, Bitget,
 * Kucoin, Bybit). The previous design called the exchange's "cancel
 * all open orders by symbol" endpoint, which Binance / Kucoin / Bybit
 * implement as a symbol-wide nuke — wiping every order on that
 * symbol regardless of which Kraite position owns it. On
 * 2026-05-03 22:50 a sequential same-symbol open-close-reopen pattern
 * (position 209 closing, position 211 freshly active on ETCUSDT)
 * caused the symbol-wide DELETE issued for 209 to take down 211's TP
 * and LIMIT ladder as collateral damage. Per-order iteration scoped
 * to the cancelling position's own rows eliminates that blast radius
 * regardless of how many Kraite positions share the symbol.
 *
 * Algo orders (STOP-MARKET / PROFIT-LIMIT) live on a separate
 * exchange endpoint and are handled by `CancelAlgoOpenOrdersJob`.
 *
 * Bumps `reference_status` to CANCELLED on every selected row before
 * issuing the API calls. That bump is the OrderObserver's intent
 * gate: when the matching cancellation lands via WS push, the order
 * row's status will equal its reference_status, and the observer's
 * `dispatchPositionReplacement` early-return on `status === reference_status`
 * keeps the per-position replacement workflow correctly silent
 * (we asked for the cancel; no replacement needed).
 */
final class CancelPositionOpenOrdersJob extends BaseApiableJob
{
    private const array CANCELLABLE_STATUSES = ['NEW', 'PARTIALLY_FILLED'];

    public Position $position;

    public function __construct(
        int $positionId,
        public bool $openingOrdersOnly = false,
    ) {
        $this->position = Position::findOrFail($positionId);
    }

    public function assignExceptionHandler(): void
    {
        $canonical = $this->position->account->apiSystem->canonical;
        $this->exceptionHandler = BaseExceptionHandler::make($canonical)
            ->withAccount($this->position->account);
    }

    public function relatable()
    {
        return $this->position;
    }

    public function computeApiable()
    {
        $orders = $this->cancellableOrdersQuery()->get();

        if ($orders->isEmpty()) {
            return [
                'position_id' => $this->position->id,
                'symbol' => $this->position->parsed_trading_pair,
                'cancelled_count' => 0,
                'idempotent_count' => 0,
                'cancelled' => [],
                'idempotent' => [],
                'message' => 'No active non-algo orders to cancel',
            ];
        }

        // Pre-bump intent flag so WS pushes for these cancellations
        // bypass the OrderObserver's replacement dispatch path.
        $orders->toQuery()->update(['reference_status' => 'CANCELLED']);

        $cancelled = [];
        $idempotent = [];

        foreach ($orders as $order) {
            try {
                $apiResponse = $order->apiCancel();
                $cancelled[] = [
                    'order_id' => $order->id,
                    'exchange_order_id' => $order->exchange_order_id,
                    'type' => $order->type,
                    'result' => $apiResponse->result,
                ];
            } catch (Throwable $e) {
                // The exchange may have already cancelled / forgotten
                // the order between selection and call (Binance
                // -2011 "Unknown order sent" is the canonical case).
                // Per-exchange handlers classify that as ignorable;
                // we mark the row CANCELLED locally and move on so a
                // single stale row doesn't kill the rest of the
                // batch. Anything non-ignorable bubbles up so the
                // framework's normal retry / fail path runs.
                if ($this->exceptionHandler->ignoreException($e)) {
                    $order->updateSaving(['status' => 'CANCELLED']);
                    $idempotent[] = [
                        'order_id' => $order->id,
                        'exchange_order_id' => $order->exchange_order_id,
                        'type' => $order->type,
                        'reason' => 'Order already gone on exchange; DB reconciled.',
                    ];

                    continue;
                }

                throw $e;
            }
        }

        return [
            'position_id' => $this->position->id,
            'symbol' => $this->position->parsed_trading_pair,
            'cancelled_count' => count($cancelled),
            'idempotent_count' => count($idempotent),
            'cancelled' => $cancelled,
            'idempotent' => $idempotent,
            'message' => 'Open orders cancelled per-order',
        ];
    }

    private function cancellableOrdersQuery(): Builder
    {
        $query = $this->position->orders()
            ->where('is_algo', false)
            ->whereIn('status', self::CANCELLABLE_STATUSES)
            ->whereNotNull('exchange_order_id')
            ->getQuery();

        if ($this->openingOrdersOnly) {
            $query->where('type', 'LIMIT');
        }

        return $query;
    }
}
