<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Financial;

use Illuminate\Support\Facades\DB;
use Kraite\Core\Enums\ProjectionFormat;
use Kraite\Core\Enums\ProjectionScenario;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\AccountIncome;

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
 * Percentages are cash-flow-proof on both sides of the fraction: each
 * day divides its trade PnL by the wallet that day actually opened
 * with, and window ROI chains those daily rates geometrically
 * (time-weighted return). A deposit therefore raises the euros the
 * system can earn without ever inflating the rate it reports.
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

    /**
     * @param  ?ReportingDay  $reportingDay  Day basis for grouping. Left null,
     *                                       it resolves to the account owner's
     *                                       configured basis, so no caller can
     *                                       silently report a trader's day in
     *                                       someone else's hours.
     */
    public function __construct(
        public readonly Account $account,
        private ?ReportingDay $reportingDay = null,
    ) {}

    /**
     * The day basis these numbers are reported on — the owner's configured
     * UTC offset, or UTC when the account has no owner on record.
     */
    public function reportingDay(): ReportingDay
    {
        return $this->reportingDay ??= ReportingDay::forUser($this->account->user);
    }

    /**
     * Latest known `total_wallet_balance` for the account. Returns null
     * when the account has no snapshots at all (newly provisioned).
     * Wallet anchor — used by projections, NOT by realized metrics.
     */
    public function currentWallet(): ?string
    {
        $latestSnapshot = $this->latestWalletSnapshot();

        return $latestSnapshot !== null
            ? (string) $latestSnapshot->total_wallet_balance
            : null;
    }

    /**
     * Auto-assess the personal capital still funding the account.
     *
     * The first recorded wallet snapshot is the tracking boundary. From
     * there, the latest wallet less exchange-reported net trading PnL is
     * the money movement not explained by trading: deposits increase the
     * basis, withdrawals reduce it. PnL is bounded by the latest wallet
     * snapshot so a newly closed trade cannot outrun a stale balance.
     *
     * A negative result means withdrawals have already recovered all
     * tracked personal capital, so the exposed basis is clamped to zero.
     *
     * @return array{
     *     amount: ?string,
     *     current_wallet: ?string,
     *     known_realized_pnl: string,
     *     tracking_started_at: ?string,
     *     tracking_ended_at: ?string,
     *     closed_positions: int,
     *     missing_pnl_positions: int,
     *     is_complete: bool,
     * }
     */
    public function investmentBasis(): array
    {
        $firstSnapshot = DB::table('account_balance_history')
            ->where('account_id', $this->account->id)
            ->whereNotNull('total_wallet_balance')
            ->whereNotNull('created_at')
            ->orderBy('created_at')
            ->orderBy('id')
            ->first(['total_wallet_balance', 'created_at']);

        $latestSnapshot = $this->latestWalletSnapshot();

        if ($firstSnapshot === null || $latestSnapshot === null || $latestSnapshot->total_wallet_balance === null) {
            return [
                'amount' => null,
                'current_wallet' => null,
                'known_realized_pnl' => '0.00000000',
                'tracking_started_at' => null,
                'tracking_ended_at' => null,
                'closed_positions' => 0,
                'missing_pnl_positions' => 0,
                'is_complete' => false,
            ];
        }

        $positionStats = DB::table('positions')
            ->where('account_id', $this->account->id)
            ->where('status', 'closed')
            ->whereNotNull('closed_at')
            ->where('closed_at', '>', (string) $firstSnapshot->created_at)
            ->where('closed_at', '<=', (string) $latestSnapshot->created_at)
            ->selectRaw('COUNT(*) AS closed_positions')
            ->selectRaw('SUM(CASE WHEN pnl IS NULL THEN 1 ELSE 0 END) AS missing_pnl_positions')
            ->selectRaw('COALESCE(SUM(pnl), 0) AS known_realized_pnl')
            ->first();

        $knownRealizedPnl = bcadd('0', (string) ($positionStats?->known_realized_pnl ?? '0'), self::SCALE);
        $amount = bcsub((string) $latestSnapshot->total_wallet_balance, $knownRealizedPnl, self::SCALE);

        if (bccomp($amount, '0', self::SCALE) < 0) {
            $amount = '0.00000000';
        }

        $missingPnlPositions = (int) ($positionStats?->missing_pnl_positions ?? 0);

        return [
            'amount' => $amount,
            'current_wallet' => (string) $latestSnapshot->total_wallet_balance,
            'known_realized_pnl' => $knownRealizedPnl,
            'tracking_started_at' => (string) $firstSnapshot->created_at,
            'tracking_ended_at' => (string) $latestSnapshot->created_at,
            'closed_positions' => (int) ($positionStats?->closed_positions ?? 0),
            'missing_pnl_positions' => $missingPnlPositions,
            'is_complete' => $missingPnlPositions === 0,
        ];
    }

    /**
     * How far back this account's income ledger is authoritative, as a
     * comparable datetime string, or null when there is no ledger.
     *
     * Read off the account already in hand rather than re-queried: callers
     * that hydrate an account without the column simply get the close-day
     * fallback, which is the same answer they got before the ledger existed.
     */
    private function incomesSyncedFrom(): ?string
    {
        $syncedFrom = $this->account->incomes_synced_from ?? null;

        if ($syncedFrom === null) {
            return null;
        }

        return $syncedFrom instanceof \DateTimeInterface
            ? $syncedFrom->format('Y-m-d H:i:s')
            : (string) $syncedFrom;
    }

    /**
     * SQL grouping expression that files a UTC timestamp column under the
     * trader's calendar day, in whichever dialect the connection speaks.
     */
    private function dayExpression(string $column): string
    {
        return $this->reportingDay()->dateExpression($column, DB::connection()->getDriverName());
    }

    private function latestWalletSnapshot(): ?object
    {
        return DB::table('account_balance_history')
            ->where('account_id', $this->account->id)
            ->whereNotNull('total_wallet_balance')
            ->whereNotNull('created_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first(['total_wallet_balance', 'created_at']);
    }

    /**
     * First wallet snapshot at or after `start`. Reporting anchor for
     * "the account opened this window with X" — ROI and the daily
     * percentages no longer divide by it, they use each day's own
     * opening wallet (see `dailyStartWallets()`).
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
     * Realized ROI inside the window as a percentage — the
     * time-weighted return of the window's daily rates. Each day is
     * scored against the capital that day actually traded and the
     * results are chained, so a deposit or withdrawal mid-window moves
     * the euros without ever moving the percentage. Returns null when
     * the window has no scoreable day (no clean closes, or no wallet
     * anchor to divide by).
     */
    public function realizedRoiPct(Window $window): ?float
    {
        return TimeWeightedReturn::fromDailyRates($this->dailyPercentages($window));
    }

    /**
     * Wallet anchor for every calendar day inside the window — the
     * money the account actually opened that day with.
     *
     * Day one of the window anchors on its own first snapshot (nothing
     * earlier is in scope); every later day anchors on the previous
     * day's closing snapshot, carried forward across snapshot gaps.
     * That is what makes the daily percentages deposit-proof: the day
     * after a transfer already divides by the new capital instead of
     * flattering the rate against a stale, smaller wallet.
     *
     * Known limitation: a transfer landing mid-day is invisible to that
     * day's own anchor — the day opened on the old balance — so the
     * transfer day itself can read slightly hot. It washes out from the
     * next day onwards.
     *
     * @return array<string, string> YYYY-MM-DD → wallet at day open.
     */
    public function dailyStartWallets(Window $window): array
    {
        $dayColumn = $this->dayExpression('created_at');

        $dayBounds = DB::table('account_balance_history')
            ->select(DB::raw($dayColumn.' AS d'))
            ->selectRaw('MIN(id) AS opening_id')
            ->selectRaw('MAX(id) AS closing_id')
            ->where('account_id', $this->account->id)
            ->whereNotNull('total_wallet_balance')
            ->whereBetween('created_at', [
                $window->start->toDateTimeString(),
                $window->end->toDateTimeString(),
            ])
            ->groupBy(DB::raw($dayColumn))
            ->get();

        if ($dayBounds->isEmpty()) {
            return [];
        }

        $anchorIds = $dayBounds
            ->flatMap(fn (object $row): array => [(int) $row->opening_id, (int) $row->closing_id])
            ->all();

        $balances = DB::table('account_balance_history')
            ->whereIn('id', $anchorIds)
            ->pluck('total_wallet_balance', 'id');

        $opening = [];
        $closing = [];

        foreach ($dayBounds as $row) {
            $openingId = (int) $row->opening_id;
            $closingId = (int) $row->closing_id;

            // Retention pruning can delete a snapshot between the two reads;
            // a day whose anchor vanished is skipped rather than resolved to
            // an empty string bcmath would reject.
            if (! isset($balances[$openingId], $balances[$closingId])) {
                continue;
            }

            $day = (string) $row->d;
            $opening[$day] = (string) $balances[$openingId];
            $closing[$day] = (string) $balances[$closingId];
        }

        // Walk the trader's calendar days, not UTC's. On a UTC+2 basis a
        // window ending at 23:00 UTC already belongs to the next trading day,
        // and walking UTC dates would drop that day's anchor on the floor.
        $anchors = [];
        $carry = null;
        $cursor = $this->reportingDay()->startOfLocalDay($window->start);
        $stop = $this->reportingDay()->startOfLocalDay($window->end);

        while ($cursor->lte($stop)) {
            $day = $cursor->toDateString();
            $anchor = $carry ?? ($opening[$day] ?? null);

            if ($anchor !== null) {
                $anchors[$day] = $anchor;
            }

            $carry = $closing[$day] ?? $carry;
            $cursor = $cursor->addDay();
        }

        return $anchors;
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
     * Per-day percentage return — `dayTradePnl / thatDaysOpeningWallet`.
     * The denominator moves with the account, so every day is scored
     * against the capital it actually traded: after a deposit the extra
     * euros a bigger wallet earns are divided by that bigger wallet, and
     * the rate stays honest instead of jumping by the transfer ratio.
     * Days without clean closes — or without a wallet anchor — are
     * absent from the map.
     *
     * @return array<string, string> YYYY-MM-DD → decimal pct (0.01 = 1%).
     */
    public function dailyPercentages(Window $window): array
    {
        $anchors = $this->dailyStartWallets($window);

        $out = [];
        foreach ($this->dailyTradePnl($window) as $day => $delta) {
            $anchor = $anchors[$day] ?? null;

            if ($anchor === null || bccomp($anchor, '0', self::SCALE) <= 0) {
                continue;
            }

            $out[$day] = bcdiv($delta, $anchor, self::SCALE);
        }

        return $out;
    }

    /**
     * Worst / best / midpoint profitable daily percentages observed
     * in the window. Losing and break-even days remain part of realized
     * performance, but do not become forward-growth assumptions.
     * Midpoint is the simple average of worst and best, not the median
     * of the daily series — chosen for compatibility with the admin
     * projections calculator.
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
        $pcts = array_filter(
            $this->dailyPercentages($window),
            static fn (string $pct): bool => bccomp($pct, '0', self::SCALE) > 0,
        );

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
     * The realized half is trade PnL / the window's time-weighted
     * return, the projected half is growth on today's wallet, so a
     * deposit inside the window lifts the euros without ever lifting
     * the percentage.
     *
     * Window default = current calendar month.
     *
     * Returns null when there is no scenario data, no wallet, or a
     * zero wallet (percentage would be undefined).
     */
    public function projected(
        ProjectionScenario $scenario,
        ?Window $window = null,
        ProjectionFormat $format = ProjectionFormat::Percentage,
    ): float|string|null {
        $window ??= Window::thisMonth();

        return ProjectionCompounder::compute(
            scenarios: $this->scenarios($window),
            currentWallet: $this->currentWallet(),
            realizedPct: $this->realizedRoiPct($window),
            realizedAmount: $this->realizedDelta($window),
            scenario: $scenario,
            window: $window,
            format: $format,
            scale: self::SCALE,
        );
    }

    /**
     * Per-day trade PnL aggregate sourced from `positions.pnl` — the
     * exchange-reported net PnL (realized PnL minus trading fees and
     * funding), i.e. the actual money the trade moved. Filters to
     * clean closes only — `status='closed'` AND `closed_at` inside
     * the window AND a non-null exchange PnL. Cancelled / failed /
     * still-active positions are ignored.
     *
     * History of this method:
     *   - `profit_percentage / 100 × margin` — assumed `margin` was the
     *     notional; it's the per-slot wallet allocation (wallet / slots),
     *     several × the real notional, so it inflated every figure (~4×).
     *   - signed `(close − open) × quantity` — price-true, but ignores
     *     fees and funding, overstating net by the round-trip cost
     *     (observed +$0.62 over 70 trades on 2026-06-07: $8.78 vs the
     *     exchange's $8.16).
     *   - `SUM(pnl)` (current) — the exchange's own net figure, exact,
     *     fee/funding-inclusive, and WAP-safe (no entry-price recon).
     *
     * @return array<string, string> YYYY-MM-DD → bcmath-scaled per-day PnL.
     */
    private function dailyTradePnl(Window $window): array
    {
        $fromLedger = $this->dailyLedgerPnl($window);

        return $fromLedger ?? $this->dailyClosePnl($window);
    }

    /**
     * Per-day trade PnL from the exchange's own income ledger, booked on the
     * day each fee and fill actually happened.
     *
     * This is what makes a day here mean the same as a day on the exchange
     * statement: a position opened one evening and closed the next morning
     * leaves its opening commission on the first day and its result on the
     * second, and funding on a still-open position counts the moment it is
     * charged rather than whenever that position eventually closes.
     *
     * Returns null when the ledger does not reach back to the start of the
     * window — the caller then falls back to close-day grouping rather than
     * reporting a month as empty because it predates the ledger.
     *
     * @return array<string, string>|null YYYY-MM-DD → bcmath-scaled PnL.
     */
    private function dailyLedgerPnl(Window $window): ?array
    {
        // The sync records how far back it actually asked the exchange for.
        // Inferring coverage from the earliest stored record would misjudge a
        // quiet period — a ledger whose first entry is Tuesday evening still
        // covers Tuesday morning, there was simply nothing to book.
        $syncedFrom = $this->incomesSyncedFrom();

        if ($syncedFrom === null || $syncedFrom > $window->start->toDateTimeString()) {
            return null;
        }

        $dayColumn = $this->dayExpression('occurred_at');

        $rows = DB::table('account_incomes')
            ->select(DB::raw($dayColumn.' AS d'))
            ->selectRaw('SUM(income) AS pnl')
            ->where('account_id', $this->account->id)
            // Trading performance only. A deposit lands in this same ledger
            // and is not profit.
            ->whereIn('income_type', AccountIncome::TRADING_TYPES)
            ->whereBetween('occurred_at', [
                $window->start->toDateTimeString(),
                $window->end->toDateTimeString(),
            ])
            ->groupBy(DB::raw($dayColumn))
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

    /**
     * Per-day trade PnL from closed positions, filed under the day each trade
     * closed. The only source available for windows older than the ledger.
     *
     * @return array<string, string>
     */
    private function dailyClosePnl(Window $window): array
    {
        $dayColumn = $this->dayExpression('closed_at');

        $rows = DB::table('positions')
            ->select(DB::raw($dayColumn.' AS d'))
            ->selectRaw('SUM(pnl) AS pnl')
            ->where('account_id', $this->account->id)
            ->where('status', 'closed')
            ->whereNotNull('closed_at')
            ->whereNotNull('pnl')
            ->whereBetween('closed_at', [
                $window->start->toDateTimeString(),
                $window->end->toDateTimeString(),
            ])
            ->groupBy(DB::raw($dayColumn))
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
