<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Financial;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Kraite\Core\Enums\ProjectionFormat;
use Kraite\Core\Enums\ProjectionScenario;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\AccountIncome;

/**
 * Cross-account ("fleet") financial calculator. Treats every live
 * account as a single aggregated equity ledger so public-facing
 * metrics (marketing site hero, sparkline, "% in profit",
 * projections) cannot drift from the per-account math used elsewhere.
 *
 * Realized metrics (delta, ROI, daily revenue, daily %, scenarios,
 * sparkline, days-in-profit, share-in-profit) are sourced from
 * closed-position trade PnL, NOT raw wallet snapshots. That makes
 * every realized number immune to deposits, withdrawals, funding
 * fees, and any other non-trading wallet movement.
 *
 * Percentages are cash-flow-proof on both sides of the fraction: each
 * day divides fleet trade PnL by the fleet wallet that day opened
 * with, and window ROI chains those daily rates geometrically
 * (time-weighted return).
 *
 * Wallet snapshots stay available as the anchor inputs for forward
 * projections — that math needs the live wallet to compound from
 * "today's actual money", not an idealised trade-only number.
 *
 * Default fleet = active + tradeable accounts. Pass an explicit
 * collection to compute over an alternate set (e.g. an admin scope
 * that includes paused accounts).
 */
final class FleetFinancials
{
    private const SCALE = 8;

    /** @var Collection<int, Account> */
    public readonly Collection $accounts;

    /**
     * Per-window memo for the day-anchor walk. Rebuilding it costs two
     * queries per account, and a single stats payload asks for the same
     * window from the rates, the ROI, and the sparkline.
     *
     * @var array<string, array<string, string>>
     */
    private array $dailyStartWalletsByWindow = [];

    /**
     * @param  Collection<int, Account>|null  $accounts  Defaults to active + tradeable.
     * @param  ?ReportingDay  $reportingDay  Day basis for the whole aggregate.
     *                                       Defaults to UTC: a fleet spans
     *                                       traders on different bases, so one
     *                                       explicit basis for the aggregate
     *                                       beats silently mixing several.
     */
    public function __construct(
        ?Collection $accounts = null,
        private readonly ReportingDay $reportingDay = new ReportingDay(0),
    ) {
        $this->accounts = $accounts ?? Account::query()
            ->active()
            ->tradeable()
            ->onActiveApiSystem()
            ->get();
    }

    /** The day basis this aggregate reports on. */
    public function reportingDay(): ReportingDay
    {
        return $this->reportingDay;
    }

    /** @return array<int, int> */
    public function ids(): array
    {
        return $this->accounts->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    public function count(): int
    {
        return $this->accounts->count();
    }

    /**
     * Sum of every account's most recent `total_wallet_balance`. The
     * "live" anchor used by the projection compound chain. Wallet
     * anchor only — realized metrics are trade-PnL driven.
     */
    public function totalCurrentWallet(): ?string
    {
        if ($this->ids() === []) {
            return null;
        }

        $sum = '0';
        $found = false;

        foreach ($this->accounts as $account) {
            $val = (new AccountFinancials($account, $this->reportingDay))->currentWallet();
            if ($val === null) {
                continue;
            }
            $sum = bcadd($sum, $val, self::SCALE);
            $found = true;
        }

        return $found ? $sum : null;
    }

    /**
     * Fleet "started window at" wallet — sum of every account's
     * first snapshot inside the window. Reporting anchor only; ROI and
     * the daily percentages divide by each day's own opening wallet
     * (see `dailyStartWallets()`).
     */
    public function totalStartWallet(Window $window): ?string
    {
        $sum = '0';
        $found = false;

        foreach ($this->accounts as $account) {
            $val = (new AccountFinancials($account, $this->reportingDay))->startWallet($window);
            if ($val === null) {
                continue;
            }
            $sum = bcadd($sum, $val, self::SCALE);
            $found = true;
        }

        return $found ? $sum : null;
    }

    /**
     * Fleet realized delta — sum of trade PnL across every cleanly-
     * closed position in the window for accounts in the fleet.
     * Returns null when zero clean closes occurred (caller decides
     * whether to render "0" or "—").
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
     * Fleet realized ROI — the time-weighted return of the fleet's
     * daily rates. Aggregated (fleet PnL over fleet capital, per day,
     * then chained), not the median or mean of per-account ROIs. A
     * deposit into any account moves the fleet's euros without moving
     * the fleet's reported percentage.
     */
    public function realizedRoiPct(Window $window): ?float
    {
        return TimeWeightedReturn::fromDailyRates($this->dailyPercentages($window));
    }

    /**
     * Fleet wallet anchor per calendar day — the sum of every account's
     * opening wallet for that day. Accounts that have no snapshot yet
     * on a given day contribute their last known balance, so the
     * denominator never dips just because one account's collector was
     * late.
     *
     * @return array<string, string> YYYY-MM-DD → fleet wallet at day open.
     */
    public function dailyStartWallets(Window $window): array
    {
        $key = $window->start->toDateTimeString().'|'.$window->end->toDateTimeString();

        return $this->dailyStartWalletsByWindow[$key] ??= $this->sumAccountDailyStartWallets($window);
    }

    /**
     * Fleet per-day PnL from the exchange income ledger, booked on the day
     * each fee and fill happened.
     *
     * All-or-nothing across the fleet: mixing a ledger-backed account with a
     * close-day one would produce an aggregate whose days mean two different
     * things. If any account's ledger does not reach the window's start, the
     * whole fleet falls back to close-day grouping.
     *
     * @param  array<int, int>  $accountIds
     * @return array<string, string>|null
     */
    private function dailyLedgerPnl(array $accountIds, Window $window): ?array
    {
        $latestStart = null;

        foreach ($this->accounts as $account) {
            $syncedFrom = $account->incomes_synced_from ?? null;

            // One unsynced account is enough to fall back: half a fleet on
            // event time and half on close time is not a number anyone can read.
            if ($syncedFrom === null) {
                return null;
            }

            $syncedFrom = $syncedFrom instanceof \DateTimeInterface
                ? $syncedFrom->format('Y-m-d H:i:s')
                : (string) $syncedFrom;

            $latestStart = $latestStart === null ? $syncedFrom : max($latestStart, $syncedFrom);
        }

        if ($latestStart === null || $latestStart > $window->start->toDateTimeString()) {
            return null;
        }

        $dayColumn = $this->dayExpression('occurred_at');

        $rows = DB::table('account_incomes')
            ->select(DB::raw($dayColumn.' AS d'))
            ->selectRaw('SUM(income) AS pnl')
            ->whereIn('account_id', $accountIds)
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
     * SQL grouping expression that files a UTC timestamp column under the
     * fleet's reporting day, in whichever dialect the connection speaks.
     */
    private function dayExpression(string $column): string
    {
        return $this->reportingDay->dateExpression($column, DB::connection()->getDriverName());
    }

    /**
     * Uncached fleet day-anchor walk — every account's per-day opening
     * wallet summed into one series, oldest day first.
     *
     * @return array<string, string> YYYY-MM-DD → fleet wallet at day open.
     */
    private function sumAccountDailyStartWallets(Window $window): array
    {
        $anchors = [];

        foreach ($this->accounts as $account) {
            foreach ((new AccountFinancials($account, $this->reportingDay))->dailyStartWallets($window) as $day => $wallet) {
                $anchors[$day] = bcadd($anchors[$day] ?? '0', $wallet, self::SCALE);
            }
        }

        ksort($anchors);

        return $anchors;
    }

    /**
     * Per-day fleet trade revenue map for the window. Each entry is
     * the sum of every account's trade PnL on that calendar day.
     * Days with no clean closes are absent from the map.
     *
     * @return array<string, string> YYYY-MM-DD → bcmath-scaled fleet trade PnL.
     */
    public function dailyRevenues(Window $window): array
    {
        return $this->dailyTradePnl($window);
    }

    /**
     * Per-day fleet pct map — `fleetTradePnl / fleetOpeningWalletThatDay`.
     * The denominator tracks the fleet's real capital day by day, so a
     * deposit into any account is absorbed by the rate instead of
     * inflating it. Days without clean closes — or without a wallet
     * anchor — are absent from the map.
     *
     * @return array<string, string> YYYY-MM-DD → decimal pct.
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
     * Fleet-level worst / best / midpoint daily percentages, derived
     * from the aggregated daily series. Same shape as
     * `AccountFinancials::scenarios()`.
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
     * Fleet-level realized + projected. Compounds today's aggregated
     * fleet wallet forward at the chosen scenario's daily pct from
     * now until window's end, then chains that onto what the window
     * already realized. Scenario percentages are trade-PnL derived and
     * the realized half is time-weighted, so the projection
     * extrapolates trader-driven returns instead of
     * cash-flow-contaminated wallet drift.
     *
     * Default window = current calendar month.
     */
    public function projected(
        ProjectionScenario $scenario,
        ?Window $window = null,
        ProjectionFormat $format = ProjectionFormat::Percentage,
    ): float|string|null {
        $window ??= Window::thisMonth();

        return ProjectionCompounder::compute(
            scenarios: $this->scenarios($window),
            currentWallet: $this->totalCurrentWallet(),
            realizedPct: $this->realizedRoiPct($window),
            realizedAmount: $this->realizedDelta($window),
            scenario: $scenario,
            window: $window,
            format: $format,
            scale: self::SCALE,
        );
    }

    /**
     * Count of green vs observed days inside the window — derived
     * from cleanly-closed-position trade PnL. A day is "observed"
     * when at least one position closed cleanly with a recorded
     * `profit_percentage`; "green" when the sum of those
     * percentages is strictly > 0. Cancelled / failed positions
     * are excluded — only `status='closed'` rows count.
     *
     * Scope is intentionally **every account in the database**, not
     * just the active+tradeable fleet. Marketing-site stat: "the
     * trading system has had N green days in this window." Paused
     * or deactivated accounts whose closed trades happened inside
     * the window still contributed to the system's track record on
     * those days, so excluding them would understate history.
     * Matches `winRate()` scope by design — both stats sit on the
     * same surface and must reconcile.
     *
     * @return array{green: int, observed: int}
     */
    public function daysInProfit(Window $window): array
    {
        $dayColumn = $this->dayExpression('closed_at');

        $rows = DB::table('positions')
            ->select(DB::raw($dayColumn.' AS d'))
            ->selectRaw('SUM(profit_percentage) AS day_pct')
            ->where('status', 'closed')
            ->whereNotNull('closed_at')
            ->whereNotNull('profit_percentage')
            ->whereBetween('closed_at', [
                $window->start->toDateTimeString(),
                $window->end->toDateTimeString(),
            ])
            ->groupBy(DB::raw($dayColumn))
            ->get();

        $green = 0;
        $observed = 0;
        foreach ($rows as $row) {
            $observed++;
            if ((float) $row->day_pct > 0) {
                $green++;
            }
        }

        return ['green' => $green, 'observed' => $observed];
    }

    /**
     * Per-trade win-rate inside the window. Counts every cleanly-
     * closed position in the database whose `closed_at` falls in
     * the window — no account-membership filter, so paused or
     * deactivated accounts whose trades closed inside the window
     * still contribute to the system's historical track record.
     *
     * Break-even trades (`profit_percentage = 0`) are EXCLUDED
     * entirely — they don't appear in the numerator or the
     * denominator. A 0% trade is neither a win nor a loss; it
     * leaves the win-rate untouched. Wins = strictly positive
     * `profit_percentage`. Total = wins + losses (no break-evens).
     *
     * Returns `win_rate_pct = null` when there are zero
     * non-break-even closes in the window so callers (UI) can
     * hide the strip rather than rendering a fake "0%".
     *
     * Sanity invariant vs `daysInProfit($window)`:
     *   `winRate.count >= daysInProfit.observed` (one position per
     *   day = equality; multiple positions per day = strict greater).
     *
     * @return array{count: int, win_rate_pct: ?float}
     */
    public function winRate(Window $window): array
    {
        $row = DB::table('positions')
            ->where('status', 'closed')
            ->whereNotNull('closed_at')
            ->whereNotNull('profit_percentage')
            ->where('profit_percentage', '!=', 0)
            ->whereBetween('closed_at', [
                $window->start->toDateTimeString(),
                $window->end->toDateTimeString(),
            ])
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('SUM(CASE WHEN profit_percentage > 0 THEN 1 ELSE 0 END) AS winners')
            ->first();

        $total = $row ? (int) $row->total : 0;

        if ($total === 0) {
            return ['count' => 0, 'win_rate_pct' => null];
        }

        return [
            'count' => $total,
            'win_rate_pct' => ((int) $row->winners / $total) * 100.0,
        ];
    }

    /**
     * Share of accounts whose realized trade PnL over the window is
     * > 0. Returns a float percentage (0–100), or null when the
     * fleet is empty / no account had any clean closes in the
     * window.
     */
    public function shareInProfit(Window $window): ?float
    {
        $eligible = 0;
        $winners = 0;

        foreach ($this->accounts as $account) {
            $delta = (new AccountFinancials($account, $this->reportingDay))->realizedDelta($window);
            if ($delta === null) {
                continue;
            }
            $eligible++;
            if (bccomp($delta, '0', self::SCALE) > 0) {
                $winners++;
            }
        }

        if ($eligible === 0) {
            return null;
        }

        return ($winners / $eligible) * 100.0;
    }

    /**
     * Fixed-bar daily-percentage series for the marketing-site
     * sparkline. Each bar = trade-PnL of that day / the fleet wallet
     * that day opened with — the same deposit-proof rate the headline
     * percentages use, so a bar can never spike on transferred money.
     * Days with no clean closes render as `pct = 0` so the bar count
     * stays stable across windows.
     *
     * @return array<int, array{d: string, pct: float}>
     */
    public function dailySparkline(Window $window): array
    {
        $revenues = $this->dailyTradePnl($window);
        $anchors = $this->dailyStartWallets($window);

        // Trader days, not UTC days — the bars must line up with the rates.
        $cursor = $this->reportingDay->startOfLocalDay($window->start);
        $stop = $this->reportingDay->startOfLocalDay($window->end);
        $out = [];

        while ($cursor->lte($stop)) {
            $iso = $cursor->toDateString();
            $delta = $revenues[$iso] ?? '0';
            $anchor = $anchors[$iso] ?? null;

            $pct = ($anchor !== null && bccomp($anchor, '0', self::SCALE) > 0)
                ? (float) bcmul(bcdiv($delta, $anchor, self::SCALE), '100', 6)
                : 0.0;

            $out[] = ['d' => $iso, 'pct' => round($pct, 4)];
            $cursor = $cursor->addDay();
        }

        return $out;
    }

    /**
     * Per-day fleet trade PnL aggregate sourced from `positions`.
     * Single SQL pass across all accounts in the fleet — avoids
     * the N+1 shape of calling AccountFinancials::dailyTradePnl()
     * for each account. Filters to clean closes only (status =
     * 'closed') so cancelled / failed positions can never paint a
     * misleading day.
     *
     * @return array<string, string> YYYY-MM-DD → bcmath-scaled fleet PnL.
     */
    private function dailyTradePnl(Window $window): array
    {
        $accountIds = $this->ids();

        if ($accountIds === []) {
            return [];
        }

        // Exchange-reported net PnL (`positions.pnl`) — realized minus fees
        // and funding. Mirrors the AccountFinancials source: it superseded
        // price-true `(close − open) × quantity` (overstated by the omitted
        // round-trip cost) which itself superseded `profit_percentage / 100
        // × margin` (margin = per-slot allocation, not notional → ~4× high).
        $fromLedger = $this->dailyLedgerPnl($accountIds, $window);

        if ($fromLedger !== null) {
            return $fromLedger;
        }

        $dayColumn = $this->dayExpression('closed_at');

        $rows = DB::table('positions')
            ->select(DB::raw($dayColumn.' AS d'))
            ->selectRaw('SUM(pnl) AS pnl')
            ->whereIn('account_id', $accountIds)
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
