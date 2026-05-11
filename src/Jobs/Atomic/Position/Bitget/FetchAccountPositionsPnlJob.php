<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\Position\Bitget;

use Carbon\Carbon;
use Kraite\Core\Jobs\Atomic\Position\FetchAccountPositionsPnlJob as BaseFetchAccountPositionsPnlJob;
use Kraite\Core\Models\Position;

/**
 * FetchAccountPositionsPnlJob (Atomic) - Bitget
 *
 * Fetches exchange-reported net PnL per position from Bitget's history-position endpoint.
 * Matches positions by symbol + direction + time window overlap.
 */
class FetchAccountPositionsPnlJob extends BaseFetchAccountPositionsPnlJob
{
    public function computeApiable(): array
    {
        $positions = Position::where('account_id', $this->account->id)
            ->where('status', 'closed')
            ->whereNull('pnl')
            ->whereNotNull('opened_at')
            ->whereNotNull('closed_at')
            ->where('closed_at', '>', now()->subMonths(3))
            ->get();

        if ($positions->isEmpty()) {
            return [
                'account_id' => $this->account->id,
                'positions_processed' => 0,
                'message' => 'No positions pending PnL fetch.',
            ];
        }

        $earliestOpen = $positions->min('opened_at');
        $latestClose = $positions->max('closed_at');

        $exchangePositions = $this->queryHistoryFromExchange(
            $earliestOpen->getTimestampMs(),
            $latestClose->addMinutes(5)->getTimestampMs()
        );

        $updated = 0;
        $skipped = 0;

        foreach ($positions as $position) {
            $matched = $this->matchExchangePosition($position, $exchangePositions);

            if ($matched === null) {
                $skipped++;

                continue;
            }

            $netProfit = $matched['netProfit'] ?? null;

            if ($netProfit === null) {
                $skipped++;

                continue;
            }

            $position->updateSaving(['pnl' => $netProfit]);
            $updated++;
        }

        return [
            'account_id' => $this->account->id,
            'positions_processed' => $positions->count(),
            'updated' => $updated,
            'skipped' => $skipped,
        ];
    }

    /**
     * Match a local position to a Bitget history-position row
     * by symbol + direction + time overlap.
     */
    public function matchExchangePosition(Position $position, array $exchangePositions): ?array
    {
        $symbol = mb_strtoupper((string) $position->parsed_trading_pair);
        $direction = mb_strtolower((string) $position->direction);

        foreach ($exchangePositions as $exPos) {
            $exSymbol = mb_strtoupper((string) ($exPos['symbol'] ?? ''));
            $exSide = mb_strtolower((string) ($exPos['holdSide'] ?? ''));

            if ($exSymbol !== $symbol || $exSide !== $direction) {
                continue;
            }

            $exCreatedAt = Carbon::createFromTimestampMs((int) ($exPos['ctime'] ?? 0));
            $exClosedAt = Carbon::createFromTimestampMs((int) ($exPos['utime'] ?? 0));

            // Time window overlap: exchange position overlaps with our position's lifetime
            if ($exCreatedAt->lte($position->closed_at) && $exClosedAt->gte($position->opened_at)) {
                return $exPos;
            }
        }

        return null;
    }

    /**
     * Query historical positions from Bitget. Extracted for testability.
     */
    protected function queryHistoryFromExchange(int $startTime, int $endTime): array
    {
        $response = $this->account->apiQueryHistoryPositions(
            productType: 'USDT-FUTURES',
            startTime: $startTime,
            endTime: $endTime
        );

        $result = $response->result;

        if (is_array($result) && isset($result['data']['list'])) {
            return $result['data']['list'];
        }

        if (is_array($result) && isset($result['list'])) {
            return $result['list'];
        }

        return [];
    }
}
