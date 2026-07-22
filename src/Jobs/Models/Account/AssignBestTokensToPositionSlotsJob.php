<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Models\Account;

use Illuminate\Support\Facades\DB;
use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Enums\RegimeBand;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSnapshot;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;
use Kraite\Core\Support\MarketRegime\Bscs;
use Kraite\Core\Support\Math;
use Kraite\Core\Trading\Kraite;
use Kraite\Core\Trading\TokenSelection\TokenSelection;

/**
 * AssignBestTokensToPositionSlotsJob
 *
 * Creates position slots based on available capacity (exchange vs DB positions).
 * Assigns the optimal ExchangeSymbol to each "new" position slot for an account.
 * Uses the account-scoped TokenSelection domain:
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

    public function startOrStop(): bool
    {
        return $this->account->fresh()?->isOnActiveApiSystem() === true;
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
        $assignedTokens = TokenSelection::forAccount($this->account)->assign();

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
     *
     * The slot calculation + position INSERTs run inside a single
     * DB::transaction with a row-level lock (lockForUpdate) on the
     * accounts row. Without the lock this is a textbook check-then-act
     * race: two concurrent runs both read the same dbLongs/dbShorts
     * pre-state and both compute available_slots > 0, then both INSERT
     * — slot cap silently breached. The 2026-04-25 17:33 incident
     * created 2 SHORT positions (#241, #242) when only 1 SHORT slot
     * was free, exactly because of this.
     *
     * The lockForUpdate pins exclusive access to the account row for
     * the duration of the transaction. Concurrent runs serialise on
     * the lock; the second one reads the post-commit count and sees
     * zero available slots.
     */
    public function createPositionSlots(): array
    {
        // Get exchange positions from the snapshot stored by QueryAccountPositionsJob.
        // Read OUTSIDE the lock — snapshot data, no DB contention concern.
        $exchangePositions = ApiSnapshot::getFrom($this->account, 'account-positions') ?? [];

        // Count exchange positions by direction
        $exchangeLongs = $this->countPositionsByDirection($exchangePositions, 'LONG');
        $exchangeShorts = $this->countPositionsByDirection($exchangePositions, 'SHORT');

        // Engine guards — read OUTSIDE the lock, depend on Kraite singleton.
        $bscs = Bscs::forAccount($this->account);
        $engine = Kraite::withAccount($this->account);
        $canOpenLongs = $engine->canOpenLongs($bscs);
        $canOpenShorts = $engine->canOpenShorts($bscs);

        // Phase 3 — regime risk snapshot, read OUTSIDE the lock. The score
        // drives the per-direction position policy and is
        // stamped on each opened position (band + direction, e.g.
        // "elevated-long") for later analysis of how regime-born positions
        // perform.
        $bscsScore = $bscs->state()->score();
        $bscsBand = $bscsScore !== null ? RegimeBand::fromScore($bscsScore)->value : null;

        return DB::transaction(function () use (
            $exchangeLongs,
            $exchangeShorts,
            $canOpenLongs,
            $canOpenShorts,
            $bscs,
            $bscsScore,
            $bscsBand
        ): array {
            // Pessimistic lock on the accounts row — every concurrent
            // AssignBest run for this account serialises here.
            $lockedAccount = Account::whereKey($this->account->id)
                ->lockForUpdate()
                ->firstOrFail();

            $dbLongs = $lockedAccount->positions()->opened()->onlyLongs()->count();
            $dbShorts = $lockedAccount->positions()->opened()->onlyShorts()->count();

            // Existing positions are never force-closed when the effective
            // BSCS cap contracts. Availability simply clamps at zero until
            // normal attrition brings the direction below its current cap.
            $availability = Bscs::forAccount($lockedAccount, $bscs->state())
                ->positions()
                ->available(
                    exchangeLongs: $exchangeLongs,
                    exchangeShorts: $exchangeShorts,
                    databaseLongs: $dbLongs,
                    databaseShorts: $dbShorts,
                );
            $currentLongs = $availability->currentLongs();
            $currentShorts = $availability->currentShorts();
            $maxLongs = $availability->maximumLongs();
            $maxShorts = $availability->maximumShorts();
            $availableLongSlots = $availability->availableLongs();
            $availableShortSlots = $availability->availableShorts();

            $createdPositions = [];

            if ($canOpenLongs && $availableLongSlots > 0) {
                for ($i = 0; $i < $availableLongSlots; $i++) {
                    $position = Position::create([
                        'account_id' => $lockedAccount->id,
                        'direction' => 'LONG',
                        'status' => 'new',
                        'bscs_band' => $bscsBand !== null ? $bscsBand.'-long' : null,
                        'bscs_score' => $bscsScore,
                    ]);
                    $createdPositions[] = ['id' => $position->id, 'direction' => 'LONG'];
                }
            }

            if ($canOpenShorts && $availableShortSlots > 0) {
                for ($i = 0; $i < $availableShortSlots; $i++) {
                    $position = Position::create([
                        'account_id' => $lockedAccount->id,
                        'direction' => 'SHORT',
                        'status' => 'new',
                        'bscs_band' => $bscsBand !== null ? $bscsBand.'-short' : null,
                        'bscs_score' => $bscsScore,
                    ]);
                    $createdPositions[] = ['id' => $position->id, 'direction' => 'SHORT'];
                }
            }

            $this->totalCreated = count($createdPositions);

            return [
                'account_id' => $lockedAccount->id,
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
        });
    }

    /**
     * Count positions by direction from exchange response.
     *
     * Handles both Binance position-mode response shapes:
     *   - HEDGE: one row per (symbol, positionSide=LONG|SHORT) with
     *     always-positive positionAmt.
     *   - ONE-WAY: one row per symbol with positionSide=BOTH and SIGNED
     *     positionAmt (positive = LONG, negative = SHORT, zero = empty).
     *
     * Falls back to Bybit's `side` (Buy/Sell) shape when positionSide is
     * absent.
     */
    public function countPositionsByDirection(array $positions, string $direction): int
    {
        return collect($positions)->filter(static function ($position) use ($direction) {
            if (isset($position['positionSide'])) {
                $positionSide = mb_strtoupper($position['positionSide']);

                // One-way mode: positionSide=BOTH; sign of positionAmt
                // gives the direction. Zero positionAmt is empty.
                if ($positionSide === 'BOTH') {
                    $amount = (string) ($position['positionAmt'] ?? '0');

                    if (Math::equal($amount, '0')) {
                        return false;
                    }

                    return ($direction === 'LONG' && Math::gt($amount, '0'))
                        || ($direction === 'SHORT' && Math::lt($amount, '0'));
                }

                // Hedge mode: literal LONG / SHORT match.
                return $positionSide === $direction;
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
            'btc_biased_restriction' => $this->account->usesBtcBiasRestriction(),
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
