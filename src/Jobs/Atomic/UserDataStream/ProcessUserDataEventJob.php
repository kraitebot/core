<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\UserDataStream;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiDataStream;
use Kraite\Core\Models\Order;
use Kraite\Core\Support\Math;
use Kraite\Core\Support\Proxies\ApiDataMapperProxy;
use Kraite\Core\Support\ValueObjects\UserDataStreamEvent;

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
 * For non-order events (account_update, listen_key_expired, margin_call)
 * the executionType is null and they are always record-only at this
 * layer. Daemon-level branches handle listen_key_expired separately.
 */
class ProcessUserDataEventJob extends BaseQueueableJob
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
        $mapper = new ApiDataMapperProxy($this->apiSystemCanonical);

        /** @var UserDataStreamEvent $event */
        $event = $mapper->resolveUserDataStreamEvent($this->payload);

        $idempotencyKey = $this->buildIdempotencyKey();

        $recorded = $this->persistEvent($event, $idempotencyKey);

        $this->logEvent($event, $recorded);

        $orderDispatched = false;
        if ($event->eventType === 'order_update' && $this->isExecutionTypeDispatched($event->executionType)) {
            $orderDispatched = $this->applyToOrderModel($event);
        }

        return [
            'account_id' => $this->accountId,
            'api_system_canonical' => $this->apiSystemCanonical,
            'raw_event_type' => $event->rawEventType,
            'event_type' => $event->eventType,
            'execution_type' => $event->executionType,
            'recorded' => $recorded,
            'order_dispatched' => $orderDispatched,
        ];
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
     * Price selection mirrors apiSyncDefault: prefer averagePrice when
     * fills have occurred (avgPrice > 0); otherwise the order's stated
     * price. Quantity follows the same rule with filledQuantity vs
     * originalQuantity. Math::gt is used because decimal strings like
     * "0.00000000" are non-null but logically zero — a naive ?? would
     * pick the wrong value on a fresh AMENDMENT (avgPrice="0" preserves
     * truthiness in PHP but we want the real price).
     */
    private function applyToOrderModel(UserDataStreamEvent $event): bool
    {
        $order = $this->resolveLocalOrder($event);

        if ($order === null) {
            return false;
        }

        $price = $event->averagePrice !== null && Math::gt($event->averagePrice, '0')
            ? $event->averagePrice
            : $event->price;

        $quantity = $event->filledQuantity !== null && Math::gt($event->filledQuantity, '0')
            ? $event->filledQuantity
            : $event->originalQuantity;

        $updates = array_filter([
            'status' => $event->normalizedStatus,
            'price' => $price,
            'quantity' => $quantity,
        ], static fn ($value) => $value !== null);

        if ($updates === []) {
            return false;
        }

        $order->updateSaving($updates);

        return true;
    }

    private function resolveLocalOrder(UserDataStreamEvent $event): ?Order
    {
        if ($event->exchangeOrderId !== null) {
            $byExchange = Order::where('exchange_order_id', $event->exchangeOrderId)->first();

            if ($byExchange !== null) {
                return $byExchange;
            }
        }

        if ($event->clientOrderId !== null) {
            return Order::where('client_order_id', $event->clientOrderId)->first();
        }

        return null;
    }
}
