<?php

declare(strict_types=1);

namespace Kraite\Core\_Jobs\Models\Account;

use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSnapshot;
use Kraite\Core\Models\Position;
use Kraite\Core\Trading\Engine;

/*
 * CreatePositionSlotsJob
 *
 * • Reads the exchange positions snapshot from api_snapshots (canonical: account-positions).
 * • Compares exchange positions vs account's max slots (total_positions_long + total_positions_short).
 * • Creates empty Position records (only direction filled) for available slots.
 * • Uses MAX(DB positions, exchange positions) for conservative slot calculation.
 */
final class CreatePositionSlotsJob extends BaseQueueableJob
{
    public Account $account;

    public int $totalCreated = 0;

    public function __construct(int $accountId)
    {
        $this->account = Account::findOrFail($accountId);
    }

    public function relatable()
    {
        return $this->account;
    }

    public function compute()
    {
        // Get exchange positions from the snapshot stored by QueryPositionsJob
        $exchangePositions = ApiSnapshot::getFrom($this->account, 'account-positions') ?? [];

        // Count exchange positions by direction
        $exchangeLongs = $this->countPositionsByDirection($exchangePositions, 'LONG');
        $exchangeShorts = $this->countPositionsByDirection($exchangePositions, 'SHORT');

        // Count DB positions by direction (opened statuses only)
        $dbLongs = $this->account->positions()->opened()->onlyLongs()->count();
        $dbShorts = $this->account->positions()->opened()->onlyShorts()->count();

        // Use MAX for conservative calculation (avoid opening more than allowed)
        $currentLongs = max($exchangeLongs, $dbLongs);
        $currentShorts = max($exchangeShorts, $dbShorts);

        // Get max slots from account configuration
        $maxLongs = $this->account->total_positions_long;
        $maxShorts = $this->account->total_positions_short;

        // Calculate available slots
        $availableLongSlots = max(0, $maxLongs - $currentLongs);
        $availableShortSlots = max(0, $maxShorts - $currentShorts);

        // Check directional guards
        $engine = Engine::withAccount($this->account);
        $canOpenLongs = $engine->canOpenLongs();
        $canOpenShorts = $engine->canOpenShorts();

        $createdPositions = [];

        // Create empty Position records for available LONG slots
        if ($canOpenLongs && $availableLongSlots > 0) {
            for ($i = 0; $i < $availableLongSlots; $i++) {
                $position = Position::create([
                    'account_id' => $this->account->id,
                    'direction' => 'LONG',
                    'status' => 'new',
                ]);
                $createdPositions[] = ['id' => $position->id, 'direction' => 'LONG'];
            }
        }

        // Create empty Position records for available SHORT slots
        if ($canOpenShorts && $availableShortSlots > 0) {
            for ($i = 0; $i < $availableShortSlots; $i++) {
                $position = Position::create([
                    'account_id' => $this->account->id,
                    'direction' => 'SHORT',
                    'status' => 'new',
                ]);
                $createdPositions[] = ['id' => $position->id, 'direction' => 'SHORT'];
            }
        }

        $this->totalCreated = count($createdPositions);

        return [
            'account_id' => $this->account->id,
            'exchange_positions' => [
                'longs' => $exchangeLongs,
                'shorts' => $exchangeShorts,
            ],
            'db_positions' => [
                'longs' => $dbLongs,
                'shorts' => $dbShorts,
            ],
            'max_slots' => [
                'longs' => $maxLongs,
                'shorts' => $maxShorts,
            ],
            'available_slots' => [
                'longs' => $availableLongSlots,
                'shorts' => $availableShortSlots,
            ],
            'created_positions' => $createdPositions,
            'total_created' => $this->totalCreated,
        ];
    }

    /**
     * Stop the workflow gracefully if no position slots were created.
     */
    public function complete(): void
    {
        if ($this->totalCreated === 0) {
            $this->stopJob();
        }
    }

    /**
     * Count positions by direction from exchange response.
     * Exchange uses 'side' field with values like 'Buy' (LONG) or 'Sell' (SHORT).
     */
    private function countPositionsByDirection(array $positions, string $direction): int
    {
        return collect($positions)->filter(static function ($position) use ($direction) {
            // Binance uses 'positionSide' with LONG/SHORT
            if (isset($position['positionSide'])) {
                return mb_strtoupper($position['positionSide']) === $direction;
            }

            // Bybit uses 'side' with Buy/Sell
            if (isset($position['side'])) {
                $side = mb_strtoupper($position['side']);

                return ($direction === 'LONG' && $side === 'BUY')
                    || ($direction === 'SHORT' && $side === 'SELL');
            }

            return false;
        })->count();
    }
}
