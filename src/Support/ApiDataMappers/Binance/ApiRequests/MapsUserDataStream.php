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
 * Path conventions used by Binance for ORDER_TRADE_UPDATE:
 *   - Top level: e (event type), E (event time ms), T (transaction time ms)
 *   - Order events nest the order body under `o`:
 *       o.s  symbol
 *       o.c  clientOrderId
 *       o.i  orderId
 *       o.X  status (NEW / PARTIALLY_FILLED / FILLED / CANCELED / EXPIRED / REJECTED)
 *       o.x  execution type (NEW / TRADE / AMENDMENT / CANCELED / EXPIRED / REJECTED)
 *       o.q  original quantity
 *       o.z  cumulative filled quantity
 *       o.l  last filled quantity
 *       o.p  price
 *       o.ap average fill price
 *       o.L  last filled price
 *
 * ALGO_UPDATE uses a different sub-shape (the post-2025 conditional/algo-
 * order event for stop-market and similar). Same envelope, but inside `o`:
 *       o.aid   algoId       (the algo order ID, parallel to o.i)
 *       o.caid  clientAlgoId (parallel to o.c)
 *       o.tp    trigger price (the stop price; o.p is "0" for stop-markets)
 *       o.X     status — NEW / FILLED / CANCELED / EXPIRED
 *       o.cp    closePosition flag (typically true for SL / TP)
 *       o.at    algo type marker — "CONDITIONAL"
 *       o.q     "0" — algo orders close the whole position, no fixed qty
 *       o.x     NOT PRESENT — algo events do not carry execution type
 *
 * Because ALGO_UPDATE has no o.x, we synthesize an executionType from
 * the status (`ALGO_<X>`) so the per-execution-type dispatch allowlist
 * can target algo events distinctly from regular ORDER_TRADE_UPDATEs.
 * The OrderObserver's price/quantity drift detection then takes over —
 * a re-broadcast of the same trigger price is a no-op (no drift), a
 * user modification on the exchange UI surfaces as drift and dispatches
 * PrepareOrderCorrectionJob.
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
        $isAlgoEvent = $rawEventType === 'ALGO_UPDATE';

        $nativeStatus = $isOrderEvent ? ($order['X'] ?? null) : null;
        $normalizedStatus = $isOrderEvent ? $this->canonicalOrderStatus($nativeStatus) : null;

        return new UserDataStreamEvent(
            rawEventType: $rawEventType,
            eventType: $eventType,
            exchangeOrderId: $isAlgoEvent
                ? $this->stringOrNull($order['aid'] ?? null)
                : $this->stringOrNull($order['i'] ?? null),
            clientOrderId: $isAlgoEvent
                ? $this->stringOrNull($order['caid'] ?? null)
                : $this->stringOrNull($order['c'] ?? null),
            symbol: $this->stringOrNull($order['s'] ?? null),
            nativeStatus: $this->stringOrNull($nativeStatus),
            normalizedStatus: $normalizedStatus,
            price: $isAlgoEvent
                ? $this->numericStringOrNull($order['tp'] ?? null)
                : $this->numericStringOrNull($order['p'] ?? null),
            averagePrice: $isAlgoEvent
                ? null
                : $this->numericStringOrNull($order['ap'] ?? null),
            originalQuantity: $this->numericStringOrNull($order['q'] ?? null),
            filledQuantity: $isAlgoEvent
                ? null
                : $this->numericStringOrNull($order['z'] ?? null),
            lastFilledPrice: $isAlgoEvent
                ? null
                : $this->numericStringOrNull($order['L'] ?? null),
            lastFilledQuantity: $isAlgoEvent
                ? null
                : $this->numericStringOrNull($order['l'] ?? null),
            executionType: $isAlgoEvent
                ? $this->synthesizeAlgoExecutionType($nativeStatus)
                : $this->stringOrNull($order['x'] ?? null),
            eventTimeMs: $this->intOrNull($envelope['E'] ?? $envelope['T'] ?? null),
            reduceOnly: $isAlgoEvent
                ? null
                : $this->boolOrNull($order['R'] ?? null),
        );
    }

    /**
     * Coerce Binance's `R` (reduceOnly) flag into a strict bool. Binance
     * sends actual JSON booleans (`true` / `false`) here, not the
     * stringified variants used for some other fields, so the tolerant
     * cast below also accepts the standard truthy strings as a
     * defensive measure against future payload drift.
     */
    private function boolOrNull(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(mb_strtolower($value), ['true', '1'], strict: true);
        }

        if (is_int($value)) {
            return $value !== 0;
        }

        return null;
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
            'ORDER_TRADE_UPDATE', 'CONDITIONAL_ORDER_TRADE_UPDATE', 'ALGO_UPDATE' => 'order_update',
            'ACCOUNT_UPDATE' => 'account_update',
            'MARGIN_CALL' => 'margin_call',
            'listenKeyExpired' => 'listen_key_expired',
            default => 'other',
        };
    }

    /**
     * Synthesize an ALGO_UPDATE execution type by namespacing the native
     * status under an `ALGO_` prefix. ALGO_UPDATE frames do not carry an
     * `o.x` execution-type field, but their status (NEW / FILLED /
     * CANCELED / EXPIRED) carries the same semantic load. Prefixing with
     * `ALGO_` keeps the dispatch allowlist explicit — `ALGO_NEW` only
     * matches algo-order events, not regular ORDER_TRADE_UPDATE NEW
     * events that we never want to dispatch through the Order model.
     */
    private function synthesizeAlgoExecutionType(?string $nativeStatus): ?string
    {
        if ($nativeStatus === null) {
            return null;
        }

        return 'ALGO_'.$nativeStatus;
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
