<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\Account;

use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSnapshot;
use Kraite\Core\Support\Health\AccountActivityFlagSync;
use Kraite\Core\Support\Health\ForeignActivityDetector;
use Kraite\Core\Support\Math;

/**
 * SyncAccountActivityFlagsJob (Atomic)
 *
 * Runs inside the position-opening chain, right after the account's
 * exchange snapshots were refreshed and BEFORE any slot assignment or
 * sizing. Compares the fresh `account-positions` / `account-open-orders`
 * snapshots against Kraite's own records and syncs the
 * allow_other_positions / allow_other_orders protection flags — so a
 * user who started trading manually since registration flips the
 * account to protected/available-balance mode within the same run, and
 * an account whose user activity is gone returns to exclusive mode.
 *
 * Missing snapshots make the evidence unreliable: protection may still
 * tighten, but never loosen.
 */
final class SyncAccountActivityFlagsJob extends BaseQueueableJob
{
    public Account $account;

    public function __construct(int $accountId)
    {
        $this->account = Account::findOrFail($accountId);
    }

    public function relatable(): Account
    {
        return $this->account;
    }

    /**
     * @return array<string, mixed>
     */
    protected function compute(): array
    {
        $positionsSnapshot = ApiSnapshot::getFrom($this->account, 'account-positions');
        $ordersSnapshot = ApiSnapshot::getFrom($this->account, 'account-open-orders');
        $snapshotsReliable = is_array($positionsSnapshot) && is_array($ordersSnapshot);

        $exchangePositionKeys = $this->exchangePositionKeys(is_array($positionsSnapshot) ? $positionsSnapshot : []);
        $exchangeOrderIds = $this->exchangeOrderIds(is_array($ordersSnapshot) ? $ordersSnapshot : []);

        $kraiteOpen = $this->account->positions()->opened()->with('orders')->get();

        $kraiteOpenOrderIds = $kraiteOpen
            ->flatMap(fn ($position) => $position->orders)
            ->whereNotIn('status', ['FILLED', 'CANCELLED', 'EXPIRED'])
            ->pluck('exchange_order_id')
            ->filter()
            ->map(fn ($id): string => (string) $id)
            ->all();

        $kraitePositionKeys = $kraiteOpen
            ->map(fn ($p): string => $p->parsed_trading_pair.':'.$p->direction)
            ->all();

        $matchWindow = (int) config('kraite.health_watchdog.orphan_kraite_match_window_minutes', 60);

        $kraiteRecentlyClosed = $this->account->positions()
            ->whereIn('status', ['closed', 'cancelled', 'failed'])
            ->where('updated_at', '>=', now()->subMinutes($matchWindow))
            ->with('orders')
            ->get();

        $foreign = ForeignActivityDetector::detect(
            exchangeOpenOrderIds: $exchangeOrderIds,
            exchangePositionKeys: $exchangePositionKeys,
            kraiteOpenOrderIds: $kraiteOpenOrderIds,
            kraitePositionKeys: $kraitePositionKeys,
            kraiteRecentlyClosedOrderIds: $kraiteRecentlyClosed
                ->flatMap(fn ($p) => $p->orders)
                ->pluck('exchange_order_id')
                ->filter()
                ->map(fn ($id): string => (string) $id)
                ->all(),
            kraiteRecentlyClosedPositionKeys: $kraiteRecentlyClosed
                ->map(fn ($p): string => $p->parsed_trading_pair.':'.$p->direction)
                ->all(),
        );

        $hasInflight = $kraiteOpen
            ->whereIn('status', ['new', 'opening', 'cancelling', 'syncing'])
            ->isNotEmpty();

        $change = AccountActivityFlagSync::sync(
            account: $this->account,
            hasForeign: $foreign->hasAny(),
            canDisable: $snapshotsReliable && ! $hasInflight,
        );

        return [
            'account_id' => $this->account->id,
            'foreign_positions' => $foreign->foreignPositionKeys,
            'foreign_orders' => $foreign->foreignOrderIds,
            'flags_change' => $change ?? 'unchanged',
        ];
    }

    /**
     * Snapshot positions are keyed `SYMBOL:SIDE` (hedge) or
     * `SYMBOL:BOTH` (one-way); normalise BOTH to the amount-signed
     * direction so the keys compare against the local DB shape.
     *
     * @param  array<string, mixed>  $positions
     * @return array<int, string>
     */
    private function exchangePositionKeys(array $positions): array
    {
        $keys = [];

        foreach ($positions as $key => $position) {
            $amount = (string) ($position['positionAmt'] ?? '0');

            if (Math::equal($amount, '0')) {
                continue;
            }

            [$symbol, $side] = array_pad(explode(':', (string) $key), 2, 'BOTH');

            if ($side === 'BOTH') {
                $side = Math::gt($amount, '0') ? 'LONG' : 'SHORT';
            }

            $keys[] = "{$symbol}:{$side}";
        }

        return $keys;
    }

    /**
     * @param  array<int, mixed>  $orders
     * @return array<int, string>
     */
    private function exchangeOrderIds(array $orders): array
    {
        $ids = [];

        foreach ($orders as $order) {
            $id = is_array($order) ? ($order['orderId'] ?? $order['algoId'] ?? null) : null;

            if ($id !== null && (string) $id !== '') {
                $ids[] = (string) $id;
            }
        }

        return $ids;
    }
}
