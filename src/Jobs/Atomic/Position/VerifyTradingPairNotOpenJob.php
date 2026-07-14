<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\Position;

use Illuminate\Support\Str;
use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Models\ApiSnapshot;
use Kraite\Core\Models\Position;

/**
 * VerifyTradingPairNotOpenJob (Atomic)
 *
 * Safeguard job that verifies the trading pair selected for this position
 * is NOT already open on the exchange. Checks both:
 * 1. Open positions (from api_snapshots 'account-positions')
 * 2. Open orders (from api_snapshots 'account-open-orders')
 *
 * If the trading pair is found in either, the workflow is stopped gracefully.
 */
class VerifyTradingPairNotOpenJob extends BaseQueueableJob
{
    public Position $position;

    public bool $tradingPairIsOpen = false;

    public ?string $reason = null;

    public function __construct(int $positionId)
    {
        $this->position = Position::findOrFail($positionId);
    }

    public function relatable()
    {
        return $this->position;
    }

    public function compute()
    {
        $account = $this->position->account;
        $tradingPair = $this->position->exchangeSymbol->parsed_trading_pair;
        $direction = $this->position->direction;

        // 1. Check open positions from api_snapshots
        $openPositions = ApiSnapshot::getFrom($account, 'account-positions') ?? [];
        $openPositionKey = collect(array_keys($openPositions))
            ->first(static fn (string $key): bool => Str::before($key, ':') === $tradingPair);

        if ($openPositionKey !== null) {
            $this->tradingPairIsOpen = true;
            $this->reason = "Position {$openPositionKey} already exists on exchange";

            return [
                'position_id' => $this->position->id,
                'trading_pair' => $tradingPair,
                'direction' => $direction,
                'is_open' => true,
                'reason' => $this->reason,
            ];
        }

        // 2. Check open orders from api_snapshots
        $openOrders = ApiSnapshot::getFrom($account, 'account-open-orders') ?? [];

        // Use parsed_trading_pair for consistent symbol matching (e.g., 'BTCUSDT')
        $matchingOrders = collect($openOrders)->filter(static function (array $order) use ($tradingPair): bool {
            return ($order['symbol'] ?? '') === $tradingPair;
        });

        if ($matchingOrders->isNotEmpty()) {
            $this->tradingPairIsOpen = true;
            $this->reason = "Open orders exist for {$tradingPair} ({$matchingOrders->count()} orders)";

            return [
                'position_id' => $this->position->id,
                'trading_pair' => $tradingPair,
                'direction' => $direction,
                'is_open' => true,
                'reason' => $this->reason,
                'open_orders_count' => $matchingOrders->count(),
            ];
        }

        return [
            'position_id' => $this->position->id,
            'trading_pair' => $tradingPair,
            'direction' => $direction,
            'is_open' => false,
            'reason' => 'Trading pair is not open on exchange',
        ];
    }

    /**
     * Stop the workflow if the trading pair is already open.
     */
    public function complete(): void
    {
        if ($this->tradingPairIsOpen) {
            $this->stopJob();
        }
    }
}
