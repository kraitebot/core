<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Financial;

use Illuminate\Support\Facades\DB;
use Kraite\Core\Enums\ProjectionFormat;
use Kraite\Core\Enums\ProjectionScenario;
use Kraite\Core\Models\Account;

/**
 * Per-account financial calculator. Single source of truth for every
 * "how is account X doing in window Y" question — both the admin
 * projections page and the marketing site read from here, so the
 * numbers cannot drift between products.
 *
 * Realized metrics (delta, ROI, daily revenue, daily %, scenarios) are
 * sourced from closed-position trade PnL, NOT raw wallet snapshots.
 * That makes every realized number immune to deposits, withdrawals,
 * funding fees, exchange-side rebates, and any other non-trading
 * wallet movement that would otherwise paint a misleading picture.
 *
 * Wallet snapshots (`currentWallet`, `startWallet`, `endWallet`) stay
 * available as the anchor inputs for forward projections — that math
 * needs the live wallet to compound from "today's actual money", not
 * an idealised trade-only number.
 *
 * Trade PnL formula: per closed position, abs PnL =
 *   (profit_percentage / 100) × margin
 * summed across positions whose `closed_at` falls inside the window
 * AND whose `status` is exactly 'closed'. Cancelled / failed /
 * still-active rows are excluded by design — only clean closes count.
 */
final class AccountFinancials
{
    private const SCALE = 8;

    public function __construct(public readonly Account $account) {}

    /**
     * Latest known `total_wallet_balance` for the account. Returns null
     * when the account has no snapshots at all (newly provisioned).
     * Wallet anchor — used by projections, NOT by realized metrics.
     */
    public function currentWallet(): ?string
    {
        $val = DB::table('account_balance_history')
            ->where('account_id', $this->account->id)
            ->orderByDesc('id')
            ->value('total_wallet_balance');

        return $val !== null ? (string) $val : null;
    }

    /**
     * First wallet snapshot at or after `start`. Used as the
     * "started window at" denominator anchor for ROI and daily %.
     */
    public function startWallet(Window $window): ?string
    {
        $val = DB::table('account_balance_history')
            ->where('account_id', $this->account->id)
            ->whereBetween('created_at', [
                $window->start->toDateTimeString(),
                $window->end->toDateTimeString(),
            ])
            ->orderBy('id')
            ->value('total_wallet_balance');

        return $val !== null ? (string) $val : null;
    }

    /**
     * Last wallet snapshot at or before `end`. Wallet anchor only —
     * realized window delta is sourced from trade PnL, not from
     * `endWallet − startWallet`.
     */
    public function endWallet(Window $window): ?string
    {
        $val = DB::table('account_balance_history')
            ->where('account_id', $this->account->id)
            ->whereBetween('created_at', [
                $window->start->toDateTimeString(),
                $window->end->toDateTimeString(),
            ])
            ->orderByDesc('id')
            ->value('total_wallet_balance');

        return $val !== null ? (string) $val : null;
    }

    /**
     * Realized money delta inside the window — sum of per-position
     * trade PnL across every cleanly-closed position in the window.
     * Returns null when the account closed zero clean positions in
     * the window (caller can decide whether to render "0" or "—").
     */
    public function realizedDelta(Window $window): ?string
    {
        $perDay = $this->dailyTradePnl($window);

        if ($perDay === []) {
            return null;
        }

        $sum = '0';
        foreach ($perDay as $delta) {
            $sum = bcadd($sum, $delta, self::SCALE);
        }

        return $sum;
    }

    /**
     * Realized ROI inside the window as a percentage. Numerator is
     * trade-only PnL (immune to cash-flow noise); denominator is
     * the start-of-window wallet anchor. Returns null when the
     * window has no clean closes OR no wallet anchor (ratio
     * undefined).
     */
    public function realizedRoiPct(Window $window): ?float
    {
        $start = $this->startWallet($window);
        $delta = $this->realizedDelta($window);

        if ($start === null || $delta === null || bccomp($start, '0', self::SCALE) <= 0) {
            return null;
        }

        return (float) bcmul(bcdiv($delta, $start, self::SCALE), '100', 6);
    }

    /**
     * Per-day trade revenue map for the window. Each entry is the
     * sum of per-position trade PnL for closed positions whose
     * `closed_at` falls on that calendar day. Days with no clean
     * closes are absent from the map.
     *
     * @return array<string, string> YYYY-MM-DD → bcmath-scaled trade PnL.
     */
    public function dailyRevenues(Window $window): array
    {
        return $this->dailyTradePnl($window);
    }

    /**
     * Per-day percentage return — `dayTradePnl / startOfWindowWallet`.
     * Constant denominator (start-of-window wallet) means the daily
     * percentages stay comparable across the window without being
     * skewed by a deposit / withdrawal mid-window. Days without
     * clean closes are absent from the map.
     *
     * @return array<string, string> YYYY-MM-DD → decimal pct (0.01 = 1%).
     */
    public function dailyPercentages(Window $window): array
    {
        $start = $this->startWallet($window);

        if ($start === null || bccomp($start, '0', self::SCALE) <= 0) {
            return [];
        }

        $out = [];
        foreach ($this->dailyTradePnl($window) as $day => $delta) {
            $out[$day] = bcdiv($delta, $start, self::SCALE);
        }

        return $out;
    }

    /**
     * Worst / best / midpoint daily percentages observed in the
     * window. Midpoint is the simple average of worst and best, not
     * the median of the daily series — chosen for compatibility
     * with the admin projections calculator.
     *
     * @return array{
     *     pessimistic_pct: ?string,
     *     neutral_pct: ?string,
     *     optimistic_pct: ?string,
     *     days_observed: int,
     * }
     */
    public function scenarios(Window $window): array
    {
        $pcts = $this->dailyPercentages($window);

        if ($pcts === []) {
            return [
                'pessimistic_pct' => null,
                'neutral_pct' => null,
                'optimistic_pct' => null,
                'days_observed' => 0,
            ];
        }

        $values = array_values($pcts);
        sort($values);

        $worst = (string) $values[0];
        $best = (string) end($values);
        $mid = bcdiv(bcadd($worst, $best, 16), '2', self::SCALE);

        return [
            'pessimistic_pct' => $worst,
            'neutral_pct' => $mid,
            'optimistic_pct' => $best,
            'days_observed' => count($pcts),
        ];
    }

    /**
     * Realized + projected window result. Compounds the current
     * wallet forward at the chosen scenario's daily pct from now
     * until the window's end, and reports the combined gain in the
     * requested format. Scenario percentages are now trade-PnL
     * derived, so the forward projection is "if the trader keeps
     * delivering at this scenario rate", not "if wallet keeps
     * drifting at this rate".
     *
     * Window default = current calendar month.
     *
     * Returns null when there is no scenario data, no start anchor,
     * or a zero starting wallet (percentage would be undefined).
     */
    public function projected(
        ProjectionScenario $scenario,
        ?Window $window = null,
        ProjectionFormat $format = ProjectionFormat::Percentage,
    ): float|string|null {
        $window ??= Window::thisMonth();

        return ProjectionCompounder::compute(
            scenarios: $this->scenarios($window),
            startWallet: $this->startWallet($window),
            currentWallet: $this->currentWallet(),
            scenario: $scenario,
            window: $window,
            format: $format,
            scale: self::SCALE,
        );
    }

    /**
     * Per-day trade PnL aggregate sourced from `positions`. Filters
     * to clean closes only — `status='closed'` AND `closed_at`
     * inside the window AND a non-null `profit_percentage`.
     * Cancelled / failed / still-active positions are ignored, so
     * the resulting numbers reflect actual trading outcomes.
     *
     * @return array<string, string> YYYY-MM-DD → bcmath-scaled per-day PnL.
     */
    private function dailyTradePnl(Window $window): array
    {
        $rows = DB::table('positions')
            ->select(DB::raw('DATE(closed_at) AS d'))
            ->selectRaw('SUM(profit_percentage / 100 * margin) AS pnl')
            ->where('account_id', $this->account->id)
            ->where('status', 'closed')
            ->whereNotNull('closed_at')
            ->whereNotNull('profit_percentage')
            ->whereNotNull('margin')
            ->whereBetween('closed_at', [
                $window->start->toDateTimeString(),
                $window->end->toDateTimeString(),
            ])
            ->groupBy(DB::raw('DATE(closed_at)'))
            ->orderBy('d')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            if (! is_numeric($row->pnl)) {
                continue;
            }
            $out[$row->d] = bcadd('0', (string) $row->pnl, self::SCALE);
        }

        return $out;
    }
}
