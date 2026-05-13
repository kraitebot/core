<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ValueObjects;

/**
 * UserDataStreamEvent
 *
 * Per-exchange data mappers parse an inbound user-data-stream WebSocket
 * frame into this normalized DTO. The router (ProcessUserDataEventJob)
 * persists it into the api_data_stream table and (when shadow mode is
 * lifted) routes order-scoped events back into the local Order model.
 *
 * Decimal fields are kept as strings so precision is never sacrificed
 * to a float round-trip — the Order model also stores price/quantity
 * as decimal strings.
 */
final class UserDataStreamEvent
{
    /**
     * @param  string  $rawEventType  Exchange-native event/channel/topic identifier preserved verbatim
     * @param  string  $eventType  Kraite normalized vocabulary: order_update / execution / position_update / account_update / margin_call / listen_key_expired / other
     * @param  string|null  $exchangeOrderId  Exchange order ID (null for events not scoped to a single order)
     * @param  string|null  $clientOrderId  Client-supplied order ID, where applicable
     * @param  string|null  $symbol  Trading pair (e.g. BTCUSDT)
     * @param  string|null  $nativeStatus  Exchange-native status string preserved verbatim (FILLED / filled / Filled)
     * @param  string|null  $normalizedStatus  Kraite canonical status (NEW / PARTIALLY_FILLED / FILLED / CANCELLED / EXPIRED / REJECTED / UNKNOWN). Mirrors Order::status vocabulary.
     * @param  string|null  $price  Order's stated price
     * @param  string|null  $averagePrice  Average fill price across all fills so far
     * @param  string|null  $originalQuantity  Order's original size when placed
     * @param  string|null  $filledQuantity  Cumulative filled at the time of this event
     * @param  string|null  $lastFilledPrice  Price of the specific fill that triggered this event
     * @param  string|null  $lastFilledQuantity  Quantity of the specific fill that triggered this event
     * @param  string|null  $executionType  Exchange-native execution type — Binance `o.x` (NEW / TRADE / AMENDMENT / CANCELED / EXPIRED / REJECTED / CALCULATED). Drives selective dispatch in ProcessUserDataEventJob: only execution types in the per-exchange allowlist trigger Order::updateSaving.
     * @param  int|null  $eventTimeMs  Exchange-claimed event time, milliseconds since epoch
     * @param  bool|null  $reduceOnly  Whether the underlying order carries the reduce-only flag (Binance `o.R`). When true on a FILLED frame for an order we do not own, the position-close detection branch in ProcessUserDataEventJob uses it as the signal that the operator manually flat-closed a position on the exchange. Null for events that have no concept of reduce-only (algo orders, account/margin updates).
     * @param  bool|null  $closePosition  Whether the underlying order is a close-position algo (Binance `o.cp`). Distinct from `reduceOnly`: a closePosition=true algo flattens whatever quantity is open at trigger time regardless of stored qty. Set on ALGO_UPDATE events only; null for non-algo frames or when the field is absent. Future stream-driven close detection branches will gate on this rather than reduceOnly so a manual algo-close path is distinguishable from a normal conditional update.
     */
    public function __construct(
        public string $rawEventType,
        public string $eventType,
        public ?string $exchangeOrderId = null,
        public ?string $clientOrderId = null,
        public ?string $symbol = null,
        public ?string $nativeStatus = null,
        public ?string $normalizedStatus = null,
        public ?string $price = null,
        public ?string $averagePrice = null,
        public ?string $originalQuantity = null,
        public ?string $filledQuantity = null,
        public ?string $lastFilledPrice = null,
        public ?string $lastFilledQuantity = null,
        public ?string $executionType = null,
        public ?int $eventTimeMs = null,
        public ?bool $reduceOnly = null,
        public ?bool $closePosition = null,
    ) {}
}
