<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\Position\Binance;

use Kraite\Core\Jobs\Atomic\Position\FetchAccountPositionsPnlJob as BaseFetchAccountPositionsPnlJob;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\Math;

/**
 * FetchAccountPositionsPnlJob (Atomic) - Binance
 *
 * Fetches exchange-reported net PnL per position from Binance's income endpoint.
 * Computes: REALIZED_PNL + COMMISSION + FUNDING_FEE = net profit.
 * Time-windowed per position to prevent cross-contamination between sequential
 * same-token positions.
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

        $updated = 0;
        $skipped = 0;

        foreach ($positions as $position) {
            $pnl = $this->fetchNetPnlForPosition($position);

            if ($pnl === null) {
                $skipped++;

                continue;
            }

            $position->updateSaving(['pnl' => $pnl]);
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
     * Fetch net PnL for a single position by querying Binance income history
     * within the position's lifetime window.
     */
    public function fetchNetPnlForPosition(Position $position): ?string
    {
        $symbol = (string) $position->parsed_trading_pair;
        $startTime = $position->opened_at->getTimestampMs();
        $endTime = $position->closed_at->addMinutes(5)->getTimestampMs();

        $realizedPnl = $this->sumIncomeEntries($symbol, 'REALIZED_PNL', $startTime, $endTime);
        $commission = $this->sumIncomeEntries($symbol, 'COMMISSION', $startTime, $endTime);
        $fundingFee = $this->sumIncomeEntries($symbol, 'FUNDING_FEE', $startTime, $endTime);

        // If no realized PnL entries found, the exchange hasn't processed it yet
        if ($realizedPnl === null) {
            return null;
        }

        $net = Math::add(
            Math::add($realizedPnl, $commission ?? '0', 8),
            $fundingFee ?? '0',
            8
        );

        return $net;
    }

    /**
     * Sum all income entries of a given type within a time window.
     * Returns null if no entries found (exchange hasn't processed yet).
     */
    public function sumIncomeEntries(string $symbol, string $incomeType, int $startTime, int $endTime): ?string
    {
        $entries = $this->queryIncomeFromExchange($symbol, $incomeType, $startTime, $endTime);

        if (empty($entries)) {
            return $incomeType === 'REALIZED_PNL' ? null : '0';
        }

        $total = '0';
        foreach ($entries as $entry) {
            if (isset($entry['income'])) {
                $total = Math::add($total, (string) $entry['income'], 8);
            }
        }

        return $total;
    }

    /**
     * Query income entries from the exchange. Extracted for testability.
     */
    protected function queryIncomeFromExchange(string $symbol, string $incomeType, int $startTime, int $endTime): array
    {
        $response = $this->account->apiQueryIncome($symbol, $incomeType, $startTime, $endTime);

        return is_array($response->result) ? $response->result : [];
    }
}
