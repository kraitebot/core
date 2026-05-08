<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Financial;

use Illuminate\Support\Facades\DB;
use Kraite\Core\Enums\ProjectionFormat;
use Kraite\Core\Enums\ProjectionScenario;
use Kraite\Core\Models\Account;

/**
 * Per-account wallet-based financial calculator. Single source of truth
 * for every "how is account X doing in window Y" question — both the
 * admin projections page and the marketing site read from here, so the
 * numbers cannot drift between products.
 *
 * Math is anchored on `account_balance_history.total_wallet_balance`
 * snapshots, never on per-trade `profit_percentage` (which is additive
 * only under fixed-notional sizing and drifts as equity grows). All
 * monetary returns use bcmath strings; ratios return float.
 */
final class AccountFinancials
{
    private const SCALE = 8;

    public function __construct(public readonly Account $account) {}

    /**
     * Latest known `total_wallet_balance` for the account. Returns null
     * when the account has no snapshots at all (newly provisioned).
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
     * First snapshot at or after `start`. Used as the "started window
     * at" anchor when computing realized ROI.
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
     * Last snapshot at or before `end`. Pairs with startWallet() to
     * give the realized delta over the window.
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
     * Realized money delta inside the window — `endWallet − startWallet`.
     * Returns a bcmath-scaled string so callers can keep decimal
     * precision when summing across accounts.
     */
    public function realizedDelta(Window $window): ?string
    {
        $start = $this->startWallet($window);
        $end = $this->endWallet($window);

        if ($start === null || $end === null) {
            return null;
        }

        return bcsub($end, $start, self::SCALE);
    }

    /**
     * Realized ROI inside the window as a percentage. Float for UI use.
     * Returns null when the start wallet is missing or zero (ratio
     * would be undefined).
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
     * Per-day wallet revenue map for the window. Each entry is the
     * delta between the first and last snapshot recorded that day.
     * Days without snapshots are absent from the map.
     *
     * @return array<string, string> YYYY-MM-DD → bcmath-scaled delta.
     */
    public function dailyRevenues(Window $window): array
    {
        $rows = $this->dailyAggregates($window);

        $out = [];
        foreach ($rows as $row) {
            $first = (string) $row->first_wallet;
            $last = (string) $row->last_wallet;
            if (! is_numeric($first) || ! is_numeric($last)) {
                continue;
            }
            $out[$row->d] = bcsub($last, $first, self::SCALE);
        }

        return $out;
    }

    /**
     * Per-day percentage return — `(last − first) / first`. Skips days
     * with a zero starting wallet (ratio undefined). Used to derive
     * the worst / best / midpoint scenario rates.
     *
     * @return array<string, string> YYYY-MM-DD → decimal pct (0.01 = 1%).
     */
    public function dailyPercentages(Window $window): array
    {
        $rows = $this->dailyAggregates($window);

        $out = [];
        foreach ($rows as $row) {
            $first = (string) $row->first_wallet;
            $last = (string) $row->last_wallet;
            if (! is_numeric($first) || ! is_numeric($last) || bccomp($first, '0', self::SCALE) <= 0) {
                continue;
            }
            $delta = bcsub($last, $first, 16);
            $out[$row->d] = bcdiv($delta, $first, self::SCALE);
        }

        return $out;
    }

    /**
     * Worst / best / midpoint daily percentages observed in the window.
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
     * Realized + projected window result. Compounds the current wallet
     * forward at the chosen scenario's daily pct from now until the
     * window's end, and reports the combined gain in the requested
     * format.
     *
     * Window default = current calendar month, so the typical call is
     * `projected(ProjectionScenario::Neutral, null, ProjectionFormat::Percentage)`.
     *
     * Returns null when there is no scenario data, no start anchor, or
     * a zero starting wallet (percentage would be undefined).
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
     * Single SQL aggregate that powers both dailyRevenues and
     * dailyPercentages. Returns first/last wallet value per day in
     * the window. MySQL-specific because of GROUP_CONCAT.
     *
     * @return iterable<object{d: string, first_wallet: string, last_wallet: string}>
     */
    private function dailyAggregates(Window $window): iterable
    {
        return DB::table('account_balance_history')
            ->select(DB::raw('DATE(created_at) AS d'))
            ->selectRaw('SUBSTRING_INDEX(GROUP_CONCAT(total_wallet_balance ORDER BY id ASC SEPARATOR ","), ",", 1) AS first_wallet')
            ->selectRaw('SUBSTRING_INDEX(GROUP_CONCAT(total_wallet_balance ORDER BY id DESC SEPARATOR ","), ",", 1) AS last_wallet')
            ->where('account_id', $this->account->id)
            ->whereBetween('created_at', [
                $window->start->toDateTimeString(),
                $window->end->toDateTimeString(),
            ])
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('d')
            ->get();
    }
}
