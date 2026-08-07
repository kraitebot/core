<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\UserDataStream;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Enums\OrderStatus;
use Kraite\Core\Jobs\Lifecycles\Position\PreparePositionReplacementJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiDataStream;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\Math;
use Kraite\Core\Support\PositionSafety;
use Kraite\Core\Support\Proxies\JobProxy;
use Kraite\Core\Support\ValueObjects\UserDataStreamEvent;
use Kraite\Core\Trading\Exchange\Exchange;
use StepDispatcher\Models\Step;

/**
 * ProcessUserDataEventJob (Atomic)
 *
 * Worker step dispatched by the user-data-stream daemon for every frame
 * received on any exchange's authenticated private WebSocket. Persists
 * the raw frame and an extracted normalized projection into
 * api_data_stream, and (when the shadow gate is lifted) routes
 * order-scoped events back into the local Order model so the
 * OrderObserver fires the right downstream workflow.
 *
 * The daemon hands us:
 *   - accountId      which kraite account the frame belongs to
 *   - apiSystemId    which exchange (binance / bitget / bybit / kucoin)
 *   - payload        the JSON-decoded frame
 *
 * The per-exchange data mapper does the path translation; everything
 * after that is exchange-agnostic.
 *
 * Selective dispatch (per-execution-type allowlist):
 *   - kraite.user_data_stream.<exchange>.dispatched_executions = []
 *   - Each entry is an EXCHANGE-NATIVE execution-type string (Binance
 *     uses NEW / TRADE / AMENDMENT / CANCELED / EXPIRED / REJECTED).
 *   - When an inbound event's executionType is in the list, the worker
 *     calls Order::updateSaving and the existing OrderObserver fires
 *     the matching downstream workflow.
 *   - Anything NOT in the list is recorded to api_data_stream only —
 *     Order model untouched. Each execution type is enabled one at a
 *     time as its downstream workflow is validated end-to-end.
 *
 * Account updates additionally dispatch an independent high-priority
 * opening-order cancellation when a normalized position update reports
 * that a locally opened position is flat. Other non-order events remain
 * record-only here; daemon-level branches handle listen_key_expired.
 */
final class ProcessUserDataEventJob extends BaseQueueableJob
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public int $accountId,
        public int $apiSystemId,
        public string $apiSystemCanonical,
        public array $payload,
    ) {}

    public function relatable()
    {
        return Account::find($this->accountId);
    }

    public function compute(): array
    {
        $mapper = Exchange::forCanonical($this->apiSystemCanonical)->mapper();

        /** @var UserDataStreamEvent $event */
        $event = $mapper->resolveUserDataStreamEvent($this->payload);

        $idempotencyKey = $this->buildIdempotencyKey();

        $recorded = $this->persistEvent($event, $idempotencyKey);

        $this->logEvent($event, $recorded);

        $orderDispatched = false;
        if ($event->eventType === 'order_update' && $this->isExecutionTypeDispatched($event->executionType)) {
            $orderDispatched = $this->applyToOrderModel($event);
        }

        $openingOrdersCancelDispatched = $this->maybeDispatchOpeningOrderCancellation($event);
        $manualClose = $this->maybeDetectManualPositionClose($event, $orderDispatched);

        return [
            'account_id' => $this->accountId,
            'api_system_canonical' => $this->apiSystemCanonical,
            'raw_event_type' => $event->rawEventType,
            'event_type' => $event->eventType,
            'execution_type' => $event->executionType,
            'recorded' => $recorded,
            'order_dispatched' => $orderDispatched,
            'opening_orders_cancel_dispatched' => $openingOrdersCancelDispatched,
            'manual_close_detected' => $manualClose,
        ];
    }

    private function maybeDispatchOpeningOrderCancellation(UserDataStreamEvent $event): bool
    {
        if ($event->eventType !== 'account_update') {
            return false;
        }

        $dispatched = false;

        foreach ($event->positionUpdates as $positionUpdate) {
            if (! Math::equal($positionUpdate['quantity'], '0')) {
                continue;
            }

            $position = $this->resolveOpenedPositionForUpdate($positionUpdate);

            if ($position === null) {
                continue;
            }

            $dispatched = $this->dispatchOpeningOrderCancellation($position, $positionUpdate) || $dispatched;
        }

        return $dispatched;
    }

    /**
     * @param  array{symbol: string, position_side: string, quantity: string}  $positionUpdate
     */
    private function resolveOpenedPositionForUpdate(array $positionUpdate): ?Position
    {
        $query = Position::query()
            ->where('account_id', $this->accountId)
            ->opened()
            ->where('parsed_trading_pair', $positionUpdate['symbol']);

        if (in_array($positionUpdate['position_side'], ['LONG', 'SHORT'], strict: true)) {
            $query->where('direction', $positionUpdate['position_side']);
        }

        $positions = $query->get();

        if ($positions->count() !== 1) {
            if ($positions->count() > 1) {
                Log::channel('user-data')->warning('[USER-DATA] flat position update matched multiple local positions', [
                    'account_id' => $this->accountId,
                    'symbol' => $positionUpdate['symbol'],
                    'position_side' => $positionUpdate['position_side'],
                    'position_ids' => $positions->pluck('id')->all(),
                ]);
            }

            return null;
        }

        return $positions->first();
    }

    /**
     * @param  array{symbol: string, position_side: string, quantity: string}  $positionUpdate
     */
    private function dispatchOpeningOrderCancellation(Position $position, array $positionUpdate): bool
    {
        $dispatched = PositionSafety::dispatchOpeningOrderCancellation($position, 'user-data-stream');

        if (! $dispatched) {
            return false;
        }

        Log::channel('user-data')->info('[USER-DATA] flat position detected; cancelling opening orders', [
            'account_id' => $this->accountId,
            'position_id' => $position->id,
            'symbol' => $positionUpdate['symbol'],
            'position_side' => $positionUpdate['position_side'],
        ]);

        return true;
    }

    /**
     * Insert the extracted event. UNIQUE on idempotency_key catches
     * re-deliveries (worker retry / reconnect-replayed frames /
     * supervisor-restart races) at INSERT time — we treat the second
     * write as a no-op.
     */
    private function persistEvent(UserDataStreamEvent $event, string $idempotencyKey): bool
    {
        try {
            ApiDataStream::create([
                'account_id' => $this->accountId,
                'api_system_id' => $this->apiSystemId,
                'raw_event_type' => $event->rawEventType,
                'event_type' => $event->eventType,
                'exchange_order_id' => $event->exchangeOrderId,
                'client_order_id' => $event->clientOrderId,
                'symbol' => $event->symbol,
                'status' => $event->nativeStatus,
                'normalized_status' => $event->normalizedStatus,
                'price' => $event->price,
                'average_price' => $event->averagePrice,
                'original_quantity' => $event->originalQuantity,
                'filled_quantity' => $event->filledQuantity,
                'last_filled_price' => $event->lastFilledPrice,
                'last_filled_quantity' => $event->lastFilledQuantity,
                'event_time' => $event->eventTimeMs !== null
                    ? now()->createFromTimestampMs($event->eventTimeMs)
                    : null,
                'received_at' => now(),
                'raw_payload' => $this->payload,
                'idempotency_key' => $idempotencyKey,
            ]);

            return true;
        } catch (QueryException $exception) {
            // 23000 is MySQL's integrity constraint violation class —
            // covers our UNIQUE on idempotency_key. Anything else is a
            // real failure that the framework should surface and retry.
            if ($exception->getCode() !== '23000') {
                throw $exception;
            }

            return false;
        }
    }

    /**
     * One-line summary to the user-data channel. Stays terse on purpose:
     * full payload already lives in api_data_stream.raw_payload, no
     * value in duplicating it to disk.
     */
    private function logEvent(UserDataStreamEvent $event, bool $recorded): void
    {
        Log::channel('user-data')->info('[USER-DATA] event received', [
            'account_id' => $this->accountId,
            'exchange' => $this->apiSystemCanonical,
            'raw_event_type' => $event->rawEventType,
            'event_type' => $event->eventType,
            'symbol' => $event->symbol,
            'exchange_order_id' => $event->exchangeOrderId,
            'native_status' => $event->nativeStatus,
            'normalized_status' => $event->normalizedStatus,
            'recorded' => $recorded,
            'event_time_ms' => $event->eventTimeMs,
        ]);
    }

    /**
     * Deterministic per-event key. Same logical event delivered twice
     * (worker retry, reconnect race, etc.) produces the same key and
     * gets caught by the api_data_stream UNIQUE constraint. We hash
     * the entire raw payload alongside the account context so even
     * payload variants we have not yet examined still produce the
     * right de-dup behaviour. md5 is sufficient — collision cost is
     * "duplicate event silently skipped", not data loss.
     */
    private function buildIdempotencyKey(): string
    {
        $canonical = json_encode(
            $this->payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        return md5("{$this->accountId}|{$this->apiSystemId}|{$canonical}");
    }

    /**
     * Selective per-execution-type allowlist gate. Returns true only when
     * the event carries an executionType that matches an entry in the
     * configured per-exchange list. An empty list means "record only";
     * a populated list means "dispatch these specific exec types into
     * the Order model and let the OrderObserver fire its downstream
     * workflow".
     */
    private function isExecutionTypeDispatched(?string $executionType): bool
    {
        if ($executionType === null) {
            return false;
        }

        $allowlist = (array) config(
            "kraite.user_data_stream.{$this->apiSystemCanonical}.dispatched_executions",
            []
        );

        return in_array($executionType, $allowlist, strict: true);
    }

    /**
     * Apply the inbound event to the local Order model. Sets only the
     * mirrored fields (status, price, quantity) — never reference_*.
     * The OrderObserver's `updated()` hook compares against reference_*
     * to decide which downstream Step (correction / WAP / close /
     * replacement) needs to fire, exactly as it does for the polling
     * SyncOrders path.
     *
     * Lookup strategy:
     *   1. exchange_order_id (preferred — Binance always populates it on
     *      every order-scoped event).
     *   2. client_order_id (fallback — useful for orders placed but
     *      whose placement response has not been processed yet).
     *
     * Working orders keep their stated price so a normal partial fill's
     * average execution price cannot masquerade as an external amendment.
     * Terminal events may switch to averagePrice for final execution truth.
     */
    private function applyToOrderModel(UserDataStreamEvent $event): bool
    {
        $order = $this->resolveLocalOrder($event);

        if ($order === null) {
            return false;
        }

        // Monotonicity guard. Burst fills deliver frames out of order —
        // the TOSHIUSDT incident (2026-07-26) had NEW / PARTIALLY_FILLED
        // frames applied AFTER the FILLED frame within the same second,
        // regressing a filled MARKET order's price to the working frame's
        // literal 0 and firing entry_price drift alerts for hours. The
        // archive row above already persisted the stale frame for audit;
        // the local order must never move backwards through its lifecycle.
        if ($event->normalizedStatus !== null
            && OrderStatus::rank($event->normalizedStatus) < OrderStatus::rank((string) $order->status)) {
            return false;
        }

        $isWorking = in_array($event->normalizedStatus, OrderStatus::workingValues(), true);
        $price = $isWorking
            ? $event->price
            : ($event->averagePrice !== null && Math::gt($event->averagePrice, '0')
                ? $event->averagePrice
                : $event->price);

        // A zero price never carries information the local row lacks:
        // MARKET frames report price=0 by design (truth lives in
        // averagePrice), so overwriting an already-known positive price
        // with 0 can only destroy state.
        if ($price !== null && ! Math::gt($price, '0') && Math::gt((string) $order->price, '0')) {
            $price = null;
        }

        // `orders.quantity` holds the ORIGINAL placed quantity. WS
        // events carry `filledQuantity` (cumulative-executed) — which
        // is NOT the same thing and must never overwrite the placed
        // value. The 2026-05-04 ONDO #271 incident corrupted the
        // local row to mid-fill values (18.5 of 81.9), causing the
        // OrderObserver-side capture of `reference_quantity` to be
        // wrong, which in turn made `ActivatePositionJob` throw on
        // a fake quantity drift and tipped the lifecycle into the
        // cancel-cascade. Cumulative fill progress lives only in
        // `api_data_stream` rows; the local Order's `quantity`
        // stays frozen at placement until the order is cancelled
        // / replaced upstream.
        $updates = array_filter([
            'status' => $event->normalizedStatus,
            'price' => $price,
        ], static fn ($value) => $value !== null);

        if ($updates === []) {
            return false;
        }

        $order->updateSaving($updates);

        return true;
    }

    private function resolveLocalOrder(UserDataStreamEvent $event): ?Order
    {
        // Account/api-system-scoped lookup. Pre-fix, the exchange_order_id
        // / client_order_id matches were global — exchange order ids are
        // not unique across accounts or api_systems, so a Binance frame
        // for account A could mutate an order belonging to account B if
        // the numeric id collided (test/prod imports, recovery rows,
        // multiple accounts on the same exchange). Constraining the
        // lookup through the order's parent position to require
        // account_id == frame account AND apiSystem.id == frame
        // api_system closes that cross-account leak.
        // Pre-bind the IDs into locals so the nested closures stay
        // $this-free. Pint's `static_lambda` rule otherwise marks the
        // outer closure static and PHP then errors with "Using $this
        // when not in object context" on the inner $q->where access.
        $accountId = $this->accountId;
        $apiSystemId = $this->apiSystemId;

        $scope = static fn ($query) => $query->whereHas(
            'position',
            static function ($q) use ($accountId, $apiSystemId): void {
                $q->where('account_id', $accountId)
                    ->whereHas('account', static fn ($qa) => $qa->where('api_system_id', $apiSystemId));
            }
        );

        if ($event->exchangeOrderId !== null) {
            $byExchange = $scope(Order::where('exchange_order_id', $event->exchangeOrderId))->first();

            if ($byExchange !== null) {
                return $byExchange;
            }
        }

        if ($event->clientOrderId !== null) {
            return $scope(Order::where('client_order_id', $event->clientOrderId))->first();
        }

        return null;
    }

    /**
     * Detect a manual / external position close from a user-data frame
     * we did not originate.
     *
     * Binance's "Close All" / market-flat action places a reduce-only
     * MARKET on its own side — never persisted in our orders table — and
     * fills it. Our existing TP/SL go to EXPIRED (not CANCELLED) on
     * close, so the OrderObserver-driven replacement chain only fires
     * once polling sync writes those EXPIREDs to our DB. That polling
     * latency is what previously made manual close take ~60s to
     * register; during that window the DCA legs sit live on the
     * exchange and could fill on an adverse move.
     *
     * This branch closes that window. Whenever a reduce-only FILL
     * arrives for an order we cannot resolve locally, we look for an
     * active position on this account+symbol and let the standard
     * replacement orchestration (PreparePositionReplacementJob →
     * QueryAccountPositionsJob → VerifyPositionExistsOnExchangeJob)
     * decide whether to close or smart-replace. The orchestrator's
     * own per-position dedup means parallel paths (this branch + the
     * eventual EXPIRED-driven OrderObserver dispatch once that
     * execution type lands in the allowlist) collapse to a single
     * Step in flight.
     *
     * Strict gate set:
     *   - eventType must be order_update (skip account_update / margin_call)
     *   - reduceOnly must be true OR closePosition must be true (a non-
     *     reduce, non-close fill is benign DCA flow). Pre-fix, only the
     *     reduceOnly half was checked; Binance ALGO_UPDATE close-position
     *     algo fills carry `o.cp=true` but no `o.R` flag (the mapper
     *     deliberately leaves reduceOnly=null for ALGO frames), so a
     *     manual-close algo trigger could not flow through this branch.
     *     With closePosition added to the DTO (see MapsUserDataStream +
     *     UserDataStreamEvent), this gate now accepts either signal.
     *   - normalizedStatus must be FILLED (PARTIAL fills do not flat the position)
     *   - applyToOrderModel must have skipped (no local match — confirms
     *     the order is not one we already track)
     *   - Account must own an active position on this symbol
     */
    private function maybeDetectManualPositionClose(UserDataStreamEvent $event, bool $orderDispatched): bool
    {
        if ($orderDispatched) {
            return false;
        }

        if ($event->eventType !== 'order_update') {
            return false;
        }

        if ($event->reduceOnly !== true && $event->closePosition !== true) {
            return false;
        }

        if ($event->normalizedStatus !== 'FILLED') {
            return false;
        }

        if ($event->symbol === null) {
            return false;
        }

        if ($this->resolveLocalOrder($event) !== null) {
            return false;
        }

        $position = $this->resolveActivePositionForSymbol($event->symbol);

        if ($position === null) {
            return false;
        }

        // Cross-process atomicity: same race class as the
        // OrderObserver dispatch sites. A manual exchange-side close
        // can produce multiple reduce-only fills in rapid succession
        // (multi-leg flatten) — without this lock, two
        // ProcessUserDataEventJob workers can both pass the dedupe
        // check and insert duplicate replacement steps for the same
        // position. `lockForUpdate` on the parent Position row
        // serialises every replacement-dispatch attempt for that
        // position across every worker.
        return DB::transaction(function () use ($event, $position): bool {
            $locked = Position::query()->whereKey($position->id)->lockForUpdate()->firstOrFail();
            $resolvedClass = JobProxy::with($locked->account)
                ->resolve(PreparePositionReplacementJob::class);

            if (Step::hasLiveWorkflow($locked, $resolvedClass)) {
                return false;
            }

            Log::channel('user-data')->info('[USER-DATA] manual close detected via reduce-only fill', [
                'account_id' => $this->accountId,
                'position_id' => $position->id,
                'symbol' => $event->symbol,
                'exchange_order_id' => $event->exchangeOrderId,
            ]);

            Step::create([
                'class' => $resolvedClass,
                'queue' => 'priority',
                'priority' => 'high',
                'relatable_type' => $locked->getMorphClass(),
                'relatable_id' => $locked->getKey(),
                'arguments' => [
                    'positionId' => $locked->id,
                    'triggerStatus' => 'EXTERNAL_FILL',
                    'message' => 'Reduce-only fill on unowned order — verifying position state',
                    'manualCloseDetected' => true,
                ],
            ]);

            return true;
        });
    }

    /**
     * Locate the active Position on this account whose parsed trading
     * pair matches the inbound symbol. Returns null when no active
     * position exists for this pair (typical, manual-close branch will
     * exit cleanly).
     */
    private function resolveActivePositionForSymbol(string $symbol): ?Position
    {
        $upper = mb_strtoupper(mb_trim($symbol));

        return Position::query()
            ->where('account_id', $this->accountId)
            ->where('status', 'active')
            ->where('parsed_trading_pair', $upper)
            ->first();
    }
}
