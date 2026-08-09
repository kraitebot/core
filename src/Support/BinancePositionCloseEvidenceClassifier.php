<?php

declare(strict_types=1);

namespace Kraite\Core\Support;

use Kraite\Core\Enums\PositionCloseAttribution;
use Kraite\Core\Models\Position;

final class BinancePositionCloseEvidenceClassifier
{
    public function __construct(
        private readonly PositionClosingOrderSemantics $semantics,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $trades
     * @param  array<string, mixed>|null  $regularOrder
     * @param  array<int, array<string, mixed>>  $algoOrders
     * @param  array<int, array<string, mixed>>  $forceOrders
     */
    public function classify(
        Position $position,
        array $trades,
        ?array $regularOrder = null,
        array $algoOrders = [],
        array $forceOrders = [],
    ): PositionCloseAttribution {
        $closingTrade = $this->latestClosingTrade($position, $trades);

        if ($closingTrade === null) {
            return PositionCloseAttribution::Unknown;
        }

        $actualOrderId = $this->stringOrNull($closingTrade['orderId'] ?? null);

        if ($actualOrderId === null) {
            return PositionCloseAttribution::Unknown;
        }

        if ($this->isForcedOrder($actualOrderId, $forceOrders)) {
            return PositionCloseAttribution::Forced;
        }

        $matchingAlgoOrders = collect($algoOrders)
            ->filter(fn (array $order): bool => $this->stringOrNull($order['actualOrderId'] ?? null) === $actualOrderId)
            ->values();

        if ($matchingAlgoOrders->count() > 1) {
            return PositionCloseAttribution::Unknown;
        }

        if ($matchingAlgoOrders->count() === 1) {
            return $this->classifyAlgoOrder($position, $matchingAlgoOrders->sole());
        }

        if ($this->isOwned($position, exchangeOrderIds: [$actualOrderId])) {
            return PositionCloseAttribution::Kraite;
        }

        if ($regularOrder === null
            || $this->stringOrNull($regularOrder['orderId'] ?? null) !== $actualOrderId
            || mb_strtoupper((string) ($regularOrder['status'] ?? '')) !== 'FILLED') {
            return PositionCloseAttribution::Unknown;
        }

        $clientOrderId = $this->stringOrNull($regularOrder['clientOrderId'] ?? null);

        if ($this->isExchangeForcedClientId($clientOrderId)) {
            return PositionCloseAttribution::Forced;
        }

        if (! $this->matchesPosition($position, $regularOrder)) {
            return PositionCloseAttribution::Unknown;
        }

        if ($this->isOwned($position, [$actualOrderId], [$clientOrderId])) {
            return PositionCloseAttribution::Kraite;
        }

        return PositionCloseAttribution::External;
    }

    /**
     * @param  array<int, array<string, mixed>>  $trades
     * @return array<string, mixed>|null
     */
    public function latestClosingTrade(Position $position, array $trades): ?array
    {
        $direction = mb_strtoupper((string) $position->direction);
        $closingSide = $direction === 'LONG' ? 'SELL' : 'BUY';
        $hedgeMode = $position->account->isHedgeMode();

        $matching = collect($trades)
            ->filter(function (array $trade) use ($closingSide, $direction, $hedgeMode): bool {
                if (mb_strtoupper((string) ($trade['side'] ?? '')) !== $closingSide) {
                    return false;
                }

                $positionSide = mb_strtoupper((string) ($trade['positionSide'] ?? ''));

                return $hedgeMode
                    ? $positionSide === $direction
                    : in_array($positionSide, ['', 'BOTH'], strict: true);
            })
            ->sort(function (array $left, array $right): int {
                $timeComparison = ((int) ($right['time'] ?? 0)) <=> ((int) ($left['time'] ?? 0));

                if ($timeComparison !== 0) {
                    return $timeComparison;
                }

                return ((int) ($right['id'] ?? 0)) <=> ((int) ($left['id'] ?? 0));
            })
            ->values();

        return $matching->first();
    }

    /** @param array<string, mixed> $order */
    private function classifyAlgoOrder(Position $position, array $order): PositionCloseAttribution
    {
        $status = mb_strtoupper((string) ($order['algoStatus'] ?? ''));

        if (! in_array($status, ['FILLED', 'FINISHED', 'TRIGGERED'], strict: true)
            || ! $this->matchesPosition($position, $order)) {
            return PositionCloseAttribution::Unknown;
        }

        $algoId = $this->stringOrNull($order['algoId'] ?? null);
        $clientAlgoId = $this->stringOrNull($order['clientAlgoId'] ?? null);

        if ($this->isExchangeForcedClientId($clientAlgoId)) {
            return PositionCloseAttribution::Forced;
        }

        if ($this->isOwned($position, [$algoId], [$clientAlgoId])) {
            return PositionCloseAttribution::Kraite;
        }

        return PositionCloseAttribution::External;
    }

    /** @param array<string, mixed> $order */
    private function matchesPosition(Position $position, array $order): bool
    {
        return $this->semantics->matches(
            hedgeMode: $position->account->isHedgeMode(),
            direction: (string) $position->direction,
            side: $this->stringOrNull($order['side'] ?? null),
            positionSide: $this->stringOrNull($order['positionSide'] ?? null),
            reduceOnly: $this->boolOrNull($order['reduceOnly'] ?? null),
            closePosition: $this->boolOrNull($order['closePosition'] ?? null),
        );
    }

    /**
     * @param  array<int, string|null>  $exchangeOrderIds
     * @param  array<int, string|null>  $clientOrderIds
     */
    private function isOwned(Position $position, array $exchangeOrderIds = [], array $clientOrderIds = []): bool
    {
        $exchangeOrderIds = array_values(array_filter($exchangeOrderIds));
        $clientOrderIds = array_values(array_filter($clientOrderIds));

        if ($exchangeOrderIds === [] && $clientOrderIds === []) {
            return false;
        }

        return $position->orders()
            ->where(function ($query) use ($clientOrderIds, $exchangeOrderIds): void {
                if ($exchangeOrderIds !== []) {
                    $query->whereIn('exchange_order_id', $exchangeOrderIds);
                }

                if ($clientOrderIds !== []) {
                    $method = $exchangeOrderIds === [] ? 'whereIn' : 'orWhereIn';
                    $query->{$method}('client_order_id', $clientOrderIds);
                }
            })
            ->exists();
    }

    /** @param array<int, array<string, mixed>> $forceOrders */
    private function isForcedOrder(string $actualOrderId, array $forceOrders): bool
    {
        return collect($forceOrders)->contains(
            fn (array $order): bool => $this->stringOrNull($order['orderId'] ?? null) === $actualOrderId,
        );
    }

    private function isExchangeForcedClientId(?string $clientOrderId): bool
    {
        if ($clientOrderId === null) {
            return false;
        }

        $clientOrderId = mb_strtolower($clientOrderId);

        return str_starts_with($clientOrderId, 'autoclose-')
            || str_starts_with($clientOrderId, 'adl_autoclose')
            || str_starts_with($clientOrderId, 'settlement_autoclose-');
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    private function boolOrNull(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
    }
}
