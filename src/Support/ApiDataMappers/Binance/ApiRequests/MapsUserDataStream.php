<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Binance\ApiRequests;

use Kraite\Core\Support\ValueObjects\UserDataStreamEvent;

/**
 * MapsUserDataStream — Binance Futures USDS-M
 *
 * Parses an inbound user-data-stream WebSocket frame into a normalized
 * UserDataStreamEvent. Read the official event reference for path
 * meanings:
 *   developers.binance.com/docs/derivatives/usds-margined-futures
 *     /user-data-streams/Event-Order-Update
 *
 * Path conventions used by Binance:
 *   - Top level: e (event type), E (event time ms), T (transaction time ms)
 *   - Order events nest the order body under `o`:
 *       o.s  symbol
 *       o.c  clientOrderId
 *       o.i  orderId
 *       o.X  status (NEW / PARTIALLY_FILLED / FILLED / CANCELED / EXPIRED / REJECTED)
 *       o.q  original quantity
 *       o.z  cumulative filled quantity
 *       o.l  last filled quantity
 *       o.p  price
 *       o.ap average fill price
 *       o.L  last filled price
 *   - ACCOUNT_UPDATE puts data under `a` and has no order context.
 *   - listenKeyExpired carries no useful body — the event type itself is
 *     the signal.
 *
 * Status mapping respects Kraite's existing canonical vocabulary used
 * by Order::status and the OrderObserver: CANCELED (Binance) becomes
 * CANCELLED (Kraite/British spelling) so observer dispatch logic
 * matches verbatim.
 */
trait MapsUserDataStream
{
    public function resolveUserDataStreamEvent(array $envelope): UserDataStreamEvent
    {
        $rawEventType = (string) ($envelope['e'] ?? '');
        $eventType = $this->canonicalUserDataEventType($rawEventType);

        $isOrderEvent = $eventType === 'order_update';
        $order = $isOrderEvent ? ($envelope['o'] ?? []) : [];

        $nativeStatus = $isOrderEvent ? ($order['X'] ?? null) : null;
        $normalizedStatus = $isOrderEvent ? $this->canonicalOrderStatus($nativeStatus) : null;

        return new UserDataStreamEvent(
            rawEventType: $rawEventType,
            eventType: $eventType,
            exchangeOrderId: $this->stringOrNull($order['i'] ?? null),
            clientOrderId: $this->stringOrNull($order['c'] ?? null),
            symbol: $this->stringOrNull($order['s'] ?? null),
            nativeStatus: $this->stringOrNull($nativeStatus),
            normalizedStatus: $normalizedStatus,
            price: $this->numericStringOrNull($order['p'] ?? null),
            averagePrice: $this->numericStringOrNull($order['ap'] ?? null),
            originalQuantity: $this->numericStringOrNull($order['q'] ?? null),
            filledQuantity: $this->numericStringOrNull($order['z'] ?? null),
            lastFilledPrice: $this->numericStringOrNull($order['L'] ?? null),
            lastFilledQuantity: $this->numericStringOrNull($order['l'] ?? null),
            executionType: $this->stringOrNull($order['x'] ?? null),
            eventTimeMs: $this->intOrNull($envelope['E'] ?? $envelope['T'] ?? null),
        );
    }

    /**
     * Map Binance's top-level event-type string to Kraite's normalized
     * vocabulary. Unknown types are funnelled into `other` so the table
     * still records them — discovery of new event types relies on
     * inspecting `other` rows after the fact.
     */
    private function canonicalUserDataEventType(string $rawEventType): string
    {
        return match ($rawEventType) {
            'ORDER_TRADE_UPDATE', 'CONDITIONAL_ORDER_TRADE_UPDATE' => 'order_update',
            'ACCOUNT_UPDATE' => 'account_update',
            'MARGIN_CALL' => 'margin_call',
            'listenKeyExpired' => 'listen_key_expired',
            default => 'other',
        };
    }

    /**
     * Translate Binance's order status into Kraite's canonical vocabulary.
     * Binance writes `CANCELED` (American spelling); Kraite uses
     * `CANCELLED` (British) everywhere — the OrderObserver and
     * downstream Steps depend on this exact spelling, so the mapper
     * normalizes here. UNKNOWN is the safety bucket for any string we
     * have not seen before.
     */
    private function canonicalOrderStatus(?string $nativeStatus): string
    {
        return match ($nativeStatus) {
            'NEW' => 'NEW',
            'PARTIALLY_FILLED' => 'PARTIALLY_FILLED',
            'FILLED' => 'FILLED',
            'CANCELED' => 'CANCELLED',
            'EXPIRED' => 'EXPIRED',
            'REJECTED' => 'REJECTED',
            default => 'UNKNOWN',
        };
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    /**
     * Decimal fields arrive from Binance as quoted strings (e.g. "0.14200").
     * Treating them as numeric and casting to (string) preserves precision
     * while filtering accidental empty / null / non-numeric values.
     */
    private function numericStringOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return (string) $value;
    }

    private function intOrNull(mixed $value): ?int
    {
        if ($value === null || ! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }
}
