<?php

declare(strict_types=1);

namespace Kraite\Core\Trading\Concerns;

use Kraite\Core\Models\Kraite as KraiteModel;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\MarketRegime\BlackSwanIndex;

trait HasTradingGuards
{
    /**
     * Global guard for opening positions. Two cascading gates:
     *
     *   1. `kraite.allow_opening_positions` — operator-controlled hard
     *      switch. False = opens off everywhere, no exceptions.
     *
     *   2. `BlackSwanIndex::shouldBlockOpens()` — system cooldown driven
     *      by `AnalyseBscsJob`. Returns true while `bscs_cooldown_until`
     *      is in the future. Critical is ABSOLUTE — there is no
     *      per-account opt-out and no operator override (both removed in
     *      Phase 3); the cooldown blocks every account uniformly.
     *
     * Existing positions are never touched — this gate only affects new
     * `kraite:cron-create-positions` cycles. WAP, sync, close, lifecycle
     * observers run normally regardless of regime.
     */
    public function canOpenPositions(): bool
    {
        $engine = KraiteModel::first();

        if ($engine === null) {
            return false;
        }

        // Global master kill — the first gate. Used for BSCS / black-swan
        // trading suspension. The kill is normally-open: NULL = trading
        // allowed (current behaviour preserved on deploy). Only an explicit
        // `can_trade = false` on the singleton suspends ALL new opens
        // fleet-wide, taking effect on the next cron tick with no deploy.
        // The legacy env CAN_TRADE (default false) is intentionally NOT the
        // fallback here — using it would halt trading the moment this gate
        // ships. The DB column is the single source of truth.
        if (($engine->can_trade ?? true) === false) {
            return false;
        }

        if (! $engine->allow_opening_positions) {
            return false;
        }

        // BSCS / black-swan open-suspension — ABSOLUTE. A Critical-armed
        // cooldown blocks new opens for every account; there is no
        // per-account opt-out and no operator override (both removed in
        // Phase 3). The master kill and allow_opening_positions above
        // still bind first.
        if (BlackSwanIndex::current()->shouldBlockOpens()) {
            return false;
        }

        return true;
    }

    /**
     * Directional guard for opening more SHORT positions.
     * Policy: if any OPEN SHORT already has all its limit orders filled, block new SHORTs.
     */
    public function canOpenShorts(): bool
    {
        if (! $this->canOpenPositions()) {
            return false;
        }

        $openShorts = $this->account->positions()
            ->opened()
            ->onlyShorts()
            ->get();

        foreach ($openShorts as $position) {
            if ($position->allLimitOrdersFilled()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Directional guard for opening more LONG positions.
     * Policy: if any OPEN LONG already has all its limit orders filled, block new LONGs.
     */
    public function canOpenLongs(): bool
    {
        if (! $this->canOpenPositions()) {
            return false;
        }

        $openLongs = $this->account->positions()
            ->opened()
            ->onlyLongs()
            ->get();

        foreach ($openLongs as $position) {
            if ($position->allLimitOrdersFilled()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Business policy to decide whether we can open new positions for the given account.
     * Rules:
     * - User must be active and allowed to trade.
     * - Account must be allowed to trade.
     * - User's billing state must permit opens (trial active OR wallet
     *   covers at least one daily debit).
     * - If user is on a tier capped at 1 account, only their designated
     *   active_account opens new positions.
     * - If no positions are open, allow.
     * - If more than half of LONGS/SHORTS are past halfway of their ladders, block.
     */
    public function canOpenNewPositions(): bool
    {
        $opened = $this->account->positions()->opened();

        // User/account gates
        if (! $this->account->user->is_active) {
            return false;
        }
        if (! $this->account->user->can_trade) {
            return false;
        }
        if (! $this->account->can_trade) {
            return false;
        }

        if ($this->account->user->isInClosingMode()) {
            return false;
        }

        $tier = $this->account->user->subscription;

        if ($tier !== null && ! $tier->hasUnlimitedAccounts() && (int) $tier->max_accounts === 1) {
            $activeId = $this->account->user->active_account_id;

            if ($activeId !== null && (int) $this->account->id !== (int) $activeId) {
                return false;
            }
        }

        // No positions opened?
        if ($opened->count() === 0) {
            return true;
        }

        // Shorts threshold rule
        $shorts = $opened->onlyShorts()->get(['id', 'direction', 'total_limit_orders']);
        $totalShorts = $shorts->count();
        $thresholdShort = intdiv($totalShorts, 2);

        if ($totalShorts > 0) {
            $tooDeepShorts = 0;

            $shorts->each(function (Position $position) use (&$tooDeepShorts): void {
                if ($position->totalLimitOrdersFilled() > $position->total_limit_orders / 2) {
                    $tooDeepShorts++;
                }
            });

            if ($tooDeepShorts > $thresholdShort) {
                return false;
            }
        }

        // Longs threshold rule
        $longs = $opened->onlyLongs()->get(['id', 'direction', 'total_limit_orders']);
        $totalLongs = $longs->count();
        $thresholdLong = intdiv($totalLongs, 2);

        if ($totalLongs > 0) {
            $tooDeepLongs = 0;

            $longs->each(function (Position $position) use (&$tooDeepLongs): void {
                if ($position->totalLimitOrdersFilled() > $position->total_limit_orders / 2) {
                    $tooDeepLongs++;
                }
            });

            if ($tooDeepLongs > $thresholdLong) {
                return false;
            }
        }

        return true;
    }
}
