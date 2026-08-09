<?php

declare(strict_types=1);

namespace Kraite\Core\Support;

use Illuminate\Support\Facades\Log;
use Kraite\Core\Contracts\PositionCloseAttributor;
use Kraite\Core\Enums\PositionCloseAttribution;
use Kraite\Core\Models\ApiDataStream;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\ValueObjects\ApiProperties;
use Throwable;

final class PositionManualCloseAttributor implements PositionCloseAttributor
{
    private const int MAX_HISTORY_WINDOW_MS = (7 * 24 * 60 * 60 * 1000) - 1_000;

    public function __construct(
        private readonly BinancePositionCloseEvidenceClassifier $classifier,
    ) {}

    public function resolve(Position $position, int $flatObservedAtMs): PositionCloseAttribution
    {
        return $this->resolveEvidence($position, $flatObservedAtMs)->attribution;
    }

    public function resolveEvidence(Position $position, int $flatObservedAtMs): PositionCloseEvidence
    {
        if ($position->account->apiSystem->canonical !== 'binance') {
            return new PositionCloseEvidence(PositionCloseAttribution::Unknown);
        }

        $archived = $this->resolveEvidenceFromArchivedEvents($position, $flatObservedAtMs);

        if ($archived->attribution !== PositionCloseAttribution::Unknown) {
            return $archived;
        }

        try {
            return $this->resolveEvidenceFromBinanceHistory($position, $flatObservedAtMs);
        } catch (Throwable $exception) {
            Log::warning('Manual close attribution history unavailable', [
                'position_id' => $position->id,
                'account_id' => $position->account_id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return new PositionCloseEvidence(PositionCloseAttribution::Unknown);
        }
    }

    private function resolveEvidenceFromArchivedEvents(Position $position, int $flatObservedAtMs): PositionCloseEvidence
    {
        $events = ApiDataStream::query()
            ->where('account_id', $position->account_id)
            ->where('api_system_id', $position->account->api_system_id)
            ->where('symbol', $position->parsed_trading_pair)
            ->where('event_type', 'order_update')
            ->where('normalized_status', 'FILLED')
            ->latest('id')
            ->limit(100)
            ->get()
            ->filter(function (ApiDataStream $event) use ($flatObservedAtMs, $position): bool {
                $eventTimeMs = $this->eventTimeMs($event);
                $openedAtMs = $position->opened_at?->getTimestampMs();

                return $eventTimeMs <= $flatObservedAtMs
                    && ($openedAtMs === null || $eventTimeMs >= $openedAtMs);
            })
            ->sortByDesc(fn (ApiDataStream $event): int => $this->eventTimeMs($event));

        foreach ($events as $event) {
            $evidence = $this->archiveEvidence($event);

            if ($this->classifier->latestClosingTrade($position, $evidence['trades']) === null) {
                continue;
            }

            $attribution = $this->classifier->classify(
                position: $position,
                trades: $evidence['trades'],
                regularOrder: $evidence['regular_order'],
                algoOrders: $evidence['algo_orders'],
                forceOrders: $evidence['force_orders'],
            );

            return $this->evidence($position, $evidence['trades'], $attribution);
        }

        return new PositionCloseEvidence(PositionCloseAttribution::Unknown);
    }

    private function resolveEvidenceFromBinanceHistory(Position $position, int $flatObservedAtMs): PositionCloseEvidence
    {
        $startTimeMs = max(
            $position->opened_at?->getTimestampMs() ?? ($flatObservedAtMs - self::MAX_HISTORY_WINDOW_MS),
            $flatObservedAtMs - self::MAX_HISTORY_WINDOW_MS,
        );

        $trades = $this->requestArray(
            $position,
            'accountTrades',
            $this->historyProperties($position, $startTimeMs, $flatObservedAtMs, 1000),
        );
        $trades = array_values(array_filter(
            $trades,
            static fn (array $trade): bool => (int) ($trade['time'] ?? 0) >= $startTimeMs
                && (int) ($trade['time'] ?? 0) <= $flatObservedAtMs,
        ));
        $closingTrade = $this->classifier->latestClosingTrade($position, $trades);

        if ($closingTrade === null) {
            return new PositionCloseEvidence(PositionCloseAttribution::Unknown);
        }

        $actualOrderId = (string) ($closingTrade['orderId'] ?? '');

        if ($actualOrderId === '') {
            return new PositionCloseEvidence(PositionCloseAttribution::Unknown);
        }

        $ownedByExchangeId = $this->classifier->classify($position, $trades);

        if ($ownedByExchangeId === PositionCloseAttribution::Kraite) {
            return $this->evidence($position, $trades, $ownedByExchangeId);
        }

        $algoOrders = $this->requestArray(
            $position,
            'getAllAlgoOrders',
            $this->historyProperties($position, $startTimeMs, $flatObservedAtMs, 1000),
        );
        $matchingAlgoOrders = array_values(array_filter(
            $algoOrders,
            static fn (array $order): bool => (string) ($order['actualOrderId'] ?? '') === $actualOrderId,
        ));

        if ($matchingAlgoOrders !== []) {
            $attribution = $this->classifier->classify(
                position: $position,
                trades: $trades,
                algoOrders: $matchingAlgoOrders,
            );

            return $this->evidence($position, $trades, $attribution);
        }

        $orderProperties = new ApiProperties;
        $orderProperties->set('relatable', $position);
        $orderProperties->set('account', $position->account);
        $orderProperties->set('options.symbol', (string) $position->parsed_trading_pair);
        $orderProperties->set('options.orderId', $actualOrderId);
        $regularOrder = $this->requestObject($position, 'getOrder', $orderProperties);
        $preliminary = $this->classifier->classify(
            position: $position,
            trades: $trades,
            regularOrder: $regularOrder,
        );

        if ($preliminary !== PositionCloseAttribution::External) {
            return $this->evidence($position, $trades, $preliminary);
        }

        $forceOrders = $this->requestArray(
            $position,
            'getForceOrders',
            $this->historyProperties($position, $startTimeMs, $flatObservedAtMs, 100),
        );

        $attribution = $this->classifier->classify(
            position: $position,
            trades: $trades,
            regularOrder: $regularOrder,
            forceOrders: $forceOrders,
        );

        return $this->evidence($position, $trades, $attribution);
    }

    /** @param array<int, array<string, mixed>> $trades */
    private function evidence(
        Position $position,
        array $trades,
        PositionCloseAttribution $attribution,
    ): PositionCloseEvidence {
        $closingTrade = $this->classifier->latestClosingTrade($position, $trades);
        $price = $closingTrade['price'] ?? $closingTrade['execPrice'] ?? null;

        return new PositionCloseEvidence(
            attribution: $attribution,
            closingPrice: Math::isPositive($price) ? (string) $price : null,
        );
    }

    private function historyProperties(
        Position $position,
        int $startTimeMs,
        int $endTimeMs,
        int $limit,
    ): ApiProperties {
        $properties = new ApiProperties;
        $properties->set('relatable', $position);
        $properties->set('account', $position->account);
        $properties->set('options.symbol', (string) $position->parsed_trading_pair);
        $properties->set('options.startTime', (string) $startTimeMs);
        $properties->set('options.endTime', (string) $endTimeMs);
        $properties->set('options.limit', (string) $limit);

        return $properties;
    }

    /** @return array<int, array<string, mixed>> */
    private function requestArray(Position $position, string $method, ApiProperties $properties): array
    {
        $response = $position->account->withApi()->{$method}($properties);
        $decoded = json_decode((string) $response->getBody(), associative: true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded) || ! array_is_list($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, 'is_array'));
    }

    /** @return array<string, mixed>|null */
    private function requestObject(Position $position, string $method, ApiProperties $properties): ?array
    {
        $response = $position->account->withApi()->{$method}($properties);
        $decoded = json_decode((string) $response->getBody(), associative: true, flags: JSON_THROW_ON_ERROR);

        return is_array($decoded) && ! array_is_list($decoded) ? $decoded : null;
    }

    /**
     * @return array{
     *     trades: array<int, array<string, mixed>>,
     *     regular_order: array<string, mixed>|null,
     *     algo_orders: array<int, array<string, mixed>>,
     *     force_orders: array<int, array<string, mixed>>
     * }
     */
    private function archiveEvidence(ApiDataStream $event): array
    {
        $payload = $event->raw_payload;
        $order = is_array($payload['o'] ?? null) ? $payload['o'] : [];
        $eventTimeMs = $this->eventTimeMs($event);
        $isAlgo = $event->raw_event_type === 'ALGO_UPDATE';
        $exchangeOrderId = (string) ($isAlgo ? ($order['aid'] ?? '') : ($order['i'] ?? ''));
        $clientOrderId = (string) ($isAlgo ? ($order['caid'] ?? '') : ($order['c'] ?? ''));
        $side = (string) ($order['S'] ?? '');
        $positionSide = (string) ($order['ps'] ?? '');
        $syntheticActualOrderId = $isAlgo ? "algo:{$exchangeOrderId}" : $exchangeOrderId;
        $trade = [
            'id' => $event->id,
            'orderId' => $syntheticActualOrderId,
            'side' => $side,
            'positionSide' => $positionSide,
            'price' => (string) ($order['L'] ?? $order['ap'] ?? '0'),
            'qty' => (string) ($order['z'] ?? $order['q'] ?? '0'),
            'time' => $eventTimeMs,
        ];
        $forced = mb_strtoupper((string) ($order['x'] ?? '')) === 'CALCULATED'
            || $this->isExchangeForcedClientId($clientOrderId);

        if ($isAlgo) {
            return [
                'trades' => [$trade],
                'regular_order' => null,
                'algo_orders' => [[
                    'algoId' => $exchangeOrderId,
                    'clientAlgoId' => $clientOrderId,
                    'actualOrderId' => $syntheticActualOrderId,
                    'algoStatus' => (string) ($order['X'] ?? ''),
                    'orderType' => (string) ($order['ot'] ?? $order['o'] ?? ''),
                    'side' => $side,
                    'positionSide' => $positionSide,
                    'closePosition' => $order['cp'] ?? null,
                    'reduceOnly' => $order['R'] ?? null,
                    'actualQty' => (string) ($order['z'] ?? $order['q'] ?? '0'),
                    'actualPrice' => (string) ($order['L'] ?? $order['ap'] ?? '0'),
                    'updateTime' => $eventTimeMs,
                ]],
                'force_orders' => $forced ? [['orderId' => $syntheticActualOrderId]] : [],
            ];
        }

        return [
            'trades' => [$trade],
            'regular_order' => [
                'orderId' => $exchangeOrderId,
                'clientOrderId' => $clientOrderId,
                'status' => (string) ($order['X'] ?? ''),
                'type' => (string) ($order['o'] ?? ''),
                'side' => $side,
                'positionSide' => $positionSide,
                'reduceOnly' => $order['R'] ?? null,
                'closePosition' => $order['cp'] ?? null,
                'executedQty' => (string) ($order['z'] ?? '0'),
                'avgPrice' => (string) ($order['ap'] ?? '0'),
                'updateTime' => $eventTimeMs,
            ],
            'algo_orders' => [],
            'force_orders' => $forced ? [['orderId' => $exchangeOrderId]] : [],
        ];
    }

    private function isExchangeForcedClientId(string $clientOrderId): bool
    {
        $clientOrderId = mb_strtolower($clientOrderId);

        return str_starts_with($clientOrderId, 'autoclose-')
            || str_starts_with($clientOrderId, 'adl_autoclose')
            || str_starts_with($clientOrderId, 'settlement_autoclose-');
    }

    private function eventTimeMs(ApiDataStream $event): int
    {
        $payload = $event->raw_payload;
        $value = $payload['E'] ?? $payload['T'] ?? null;

        if (is_numeric($value)) {
            return (int) $value;
        }

        return $event->event_time?->getTimestampMs() ?? $event->received_at->getTimestampMs();
    }
}
