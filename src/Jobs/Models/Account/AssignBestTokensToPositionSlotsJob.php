<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Models\Account;

use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Trading\Kraite;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSnapshot;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;

/**
 * AssignBestTokensToPositionSlotsJob
 *
 * Creates position slots based on available capacity (exchange vs DB positions).
 * Assigns the optimal ExchangeSymbol to each "new" position slot for an account.
 * Uses the HasTokenDiscovery trait's algorithm:
 *   - Priority 1: Fast-tracked tokens (recently profitable quick trades)
 *   - Priority 2: Elasticity-based scoring (correlation × elasticity metrics)
 * Runs as a single job per account to prevent race conditions.
 * Force deletes any position slots that couldn't be assigned a token.
 */
final class AssignBestTokensToPositionSlotsJob extends BaseQueueableJob
{
    public Account $account;

    public int $totalCreated = 0;

    public int $assignedCount = 0;

    public int $deletedCount = 0;

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
        // Step 1: Create Position Slots
        // Read exchange positions, compare with max slots, create empty Position records.
        $slotData = $this->createPositionSlots();

        // If no slots were created, return early
        if ($this->totalCreated === 0) {
            return array_merge($slotData, [
                'assigned_tokens' => '',
                'assigned_count' => 0,
                'deleted_count' => 0,
                'stop_reason' => null,
            ]);
        }

        // Step 2: Check BTC direction before attempting token assignment
        // This allows us to provide a clear stop_reason in the response
        $btcContext = $this->getBtcDirectionContext();

        // Step 3: Assign Best Tokens to Position Slots
        // Use HasTokenDiscovery trait to assign optimal tokens.
        $assignedTokens = $this->account->assignBestTokenToNewPositions();

        // Count how many positions were successfully assigned
        $this->assignedCount = $this->account->positions()
            ->where('status', 'new')
            ->whereNotNull('exchange_symbol_id')
            ->count();

        // Force delete any position slots that couldn't be assigned a token
        $unassignedPositions = $this->account->positions()
            ->where('status', 'new')
            ->whereNull('exchange_symbol_id')
            ->get();

        $this->deletedCount = $unassignedPositions->count();

        foreach ($unassignedPositions as $position) {
            $position->forceDelete();
        }

        // Determine stop_reason if no tokens were assigned
        $stopReason = $this->determineStopReason($btcContext, $assignedTokens);

        return array_merge($slotData, [
            'assigned_tokens' => mb_trim($assignedTokens),
            'assigned_count' => $this->assignedCount,
            'deleted_count' => $this->deletedCount,
            'btc_context' => $btcContext,
            'stop_reason' => $stopReason,
        ]);
    }

    /**
     * Stop the workflow gracefully if no slots created or no tokens assigned.
     */
    public function complete(): void
    {
        if ($this->totalCreated === 0 || $this->assignedCount === 0) {
            $this->stopJob();
        }
    }

    /**
     * Create position slots based on available capacity.
     */
    public function createPositionSlots(): array
    {
        // Get exchange positions from the snapshot stored by QueryAccountPositionsJob
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
        $engine = Kraite::withAccount($this->account);
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
     * Count positions by direction from exchange response.
     */
    public function countPositionsByDirection(array $positions, string $direction): int
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

    /**
     * Get BTC direction context for the account's exchange.
     * Used to provide clear feedback in the response payload.
     */
    public function getBtcDirectionContext(): array
    {
        $btcSymbol = Symbol::where('token', config('kraite.correlation.btc_token', 'BTC'))->first();
        $btcExchangeSymbol = null;

        if ($btcSymbol) {
            $btcExchangeSymbol = ExchangeSymbol::query()
                ->where('symbol_id', $btcSymbol->id)
                ->where('api_system_id', $this->account->api_system_id)
                ->where('quote', $this->account->trading_quote)
                ->first();
        }

        return [
            'btc_direction' => $btcExchangeSymbol?->direction,
            'btc_timeframe' => $btcExchangeSymbol?->indicators_timeframe,
            'btc_biased_restriction' => config('kraite.token_discovery.btc_biased_restriction', true),
        ];
    }

    /**
     * Determine why token assignment failed, if applicable.
     */
    public function determineStopReason(array $btcContext, string $assignedTokens): ?string
    {
        // If tokens were assigned, no stop reason
        if ($assignedTokens !== '') {
            return null;
        }

        // Check if BTC has no direction and strict mode is enabled
        if ($btcContext['btc_direction'] === null && $btcContext['btc_biased_restriction'] === true) {
            return 'BTC has no direction and btc_biased_restriction=true (STRICT mode). Set BTC direction or disable strict mode.';
        }

        // If we got here, tokens weren't assigned for another reason
        if ($this->assignedCount === 0 && $this->deletedCount > 0) {
            return 'No eligible tokens matched the correlation/elasticity requirements for the position directions.';
        }

        return null;
    }
}
