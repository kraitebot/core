<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Financial;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Kraite\Core\Enums\ProjectionFormat;
use Kraite\Core\Enums\ProjectionScenario;
use Kraite\Core\Models\Account;

/**
 * Cross-account ("fleet") financial calculator. Treats every live
 * account as a single aggregated equity ledger — sum of wallets in,
 * sum of deltas out — so public-facing metrics (marketing site hero,
 * sparkline, "% in profit", projections) cannot drift from the
 * per-account math used elsewhere.
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
     * @param  Collection<int, Account>|null  $accounts  Defaults to active + tradeable.
     */
    public function __construct(?Collection $accounts = null)
    {
        $this->accounts = $accounts ?? Account::query()->active()->tradeable()->get();
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
     * "live" anchor used by the projection compound chain.
     */
    public function totalCurrentWallet(): ?string
    {
        if ($this->ids() === []) {
            return null;
        }

        $sum = '0';
        $found = false;

        foreach ($this->accounts as $account) {
            $val = (new AccountFinancials($account))->currentWallet();
            if ($val === null) {
                continue;
            }
            $sum = bcadd($sum, $val, self::SCALE);
            $found = true;
        }

        return $found ? $sum : null;
    }

    /**
     * Fleet "started window at" wallet — sum of every account's first
     * snapshot inside the window. Accounts with no snapshot in the
     * window contribute zero (they didn't exist yet for this window).
     */
    public function totalStartWallet(Window $window): ?string
    {
        $sum = '0';
        $found = false;

        foreach ($this->accounts as $account) {
            $val = (new AccountFinancials($account))->startWallet($window);
            if ($val === null) {
                continue;
            }
            $sum = bcadd($sum, $val, self::SCALE);
            $found = true;
        }

        return $found ? $sum : null;
    }

    /**
     * Fleet realized delta = `endWallet − startWallet` summed across
     * accounts. Returns null when the fleet has no anchors at all.
     */
    public function realizedDelta(Window $window): ?string
    {
        $sum = '0';
        $found = false;

        foreach ($this->accounts as $account) {
            $delta = (new AccountFinancials($account))->realizedDelta($window);
            if ($delta === null) {
                continue;
            }
            $sum = bcadd($sum, $delta, self::SCALE);
            $found = true;
        }

        return $found ? $sum : null;
    }

    /**
     * Fleet realized ROI in the window as a percentage. Aggregated
     * (sum/sum), not median or mean of per-account ROIs — large
     * accounts naturally weight the result, which is what the
     * marketing site wants to advertise.
     */
    public function realizedRoiPct(Window $window): ?float
    {
        $start = $this->totalStartWallet($window);
        $delta = $this->realizedDelta($window);

        if ($start === null || $delta === null || bccomp($start, '0', self::SCALE) <= 0) {
            return null;
        }

        return (float) bcmul(bcdiv($delta, $start, self::SCALE), '100', 6);
    }

    /**
     * Per-day fleet revenue map for the window. Each entry is the
     * sum of every account's daily wallet delta for that date.
     * Days where no account had snapshots are absent from the map.
     *
     * @return array<string, string> YYYY-MM-DD → bcmath-scaled fleet delta.
     */
    public function dailyRevenues(Window $window): array
    {
        $combined = [];

        foreach ($this->accounts as $account) {
            $rev = (new AccountFinancials($account))->dailyRevenues($window);
            foreach ($rev as $day => $delta) {
                $combined[$day] = isset($combined[$day])
                    ? bcadd($combined[$day], $delta, self::SCALE)
                    : $delta;
            }
        }

        ksort($combined);

        return $combined;
    }

    /**
     * Per-day fleet pct map — `fleetDelta / fleetStartOfDayWallet`.
     * Aggregated across accounts: numerator = summed deltas, denom =
     * summed first-of-day wallets. Days missing a denominator are
     * skipped.
     *
     * @return array<string, string> YYYY-MM-DD → decimal pct.
     */
    public function dailyPercentages(Window $window): array
    {
        $deltas = [];
        $starts = [];

        foreach ($this->accounts as $account) {
            $rows = $this->dailyAggregatesFor($account->id, $window);
            foreach ($rows as $row) {
                $first = (string) $row->first_wallet;
                $last = (string) $row->last_wallet;
                if (! is_numeric($first) || ! is_numeric($last)) {
                    continue;
                }
                $deltas[$row->d] = isset($deltas[$row->d])
                    ? bcadd($deltas[$row->d], bcsub($last, $first, self::SCALE), self::SCALE)
                    : bcsub($last, $first, self::SCALE);
                $starts[$row->d] = isset($starts[$row->d])
                    ? bcadd($starts[$row->d], $first, self::SCALE)
                    : $first;
            }
        }

        $out = [];
        foreach ($deltas as $day => $delta) {
            if (! isset($starts[$day]) || bccomp($starts[$day], '0', self::SCALE) <= 0) {
                continue;
            }
            $out[$day] = bcdiv($delta, $starts[$day], self::SCALE);
        }

        ksort($out);

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
     * now until window's end, returns combined gain in the requested
     * format. Default window = current calendar month.
     *
     * Marketing-site hero call:
     *   $fleet->projected(ProjectionScenario::Neutral, null, ProjectionFormat::Percentage);
     */
    public function projected(
        ProjectionScenario $scenario,
        ?Window $window = null,
        ProjectionFormat $format = ProjectionFormat::Percentage,
    ): float|string|null {
        $window ??= Window::thisMonth();

        return ProjectionCompounder::compute(
            scenarios: $this->scenarios($window),
            startWallet: $this->totalStartWallet($window),
            currentWallet: $this->totalCurrentWallet(),
            scenario: $scenario,
            window: $window,
            format: $format,
            scale: self::SCALE,
        );
    }

    /**
     * Share of accounts whose realized delta over the window is > 0.
     * Returns a float percentage (0–100), or null when the fleet is
     * empty / has no anchored accounts.
     */
    public function shareInProfit(Window $window): ?float
    {
        $eligible = 0;
        $winners = 0;

        foreach ($this->accounts as $account) {
            $delta = (new AccountFinancials($account))->realizedDelta($window);
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
     * 14-day-style sparkline, fleet-aggregated. Each bar = sum of
     * every account's wallet delta for that day. Days with no fleet
     * activity render as `pct = 0` so the bar count stays stable.
     *
     * @return array<int, array{d: string, pct: float}>
     */
    public function dailySparkline(Window $window): array
    {
        $revenues = $this->dailyRevenues($window);
        $startWallet = $this->totalStartWallet($window);

        $cursor = $window->start->startOfDay();
        $stop = $window->end->startOfDay();
        $out = [];

        while ($cursor->lte($stop)) {
            $iso = $cursor->toDateString();
            $delta = $revenues[$iso] ?? '0';

            $pct = ($startWallet !== null && bccomp($startWallet, '0', self::SCALE) > 0)
                ? (float) bcmul(bcdiv($delta, $startWallet, self::SCALE), '100', 6)
                : 0.0;

            $out[] = ['d' => $iso, 'pct' => round($pct, 4)];
            $cursor = $cursor->addDay();
        }

        return $out;
    }

    /**
     * Same daily aggregate query used by AccountFinancials. Inlined
     * here so the fleet variant can build per-day sums without
     * instantiating one AccountFinancials per call.
     *
     * @return iterable<object{d: string, first_wallet: string, last_wallet: string}>
     */
    private function dailyAggregatesFor(int $accountId, Window $window): iterable
    {
        return DB::table('account_balance_history')
            ->select(DB::raw('DATE(created_at) AS d'))
            ->selectRaw('SUBSTRING_INDEX(GROUP_CONCAT(total_wallet_balance ORDER BY id ASC SEPARATOR ","), ",", 1) AS first_wallet')
            ->selectRaw('SUBSTRING_INDEX(GROUP_CONCAT(total_wallet_balance ORDER BY id DESC SEPARATOR ","), ",", 1) AS last_wallet')
            ->where('account_id', $accountId)
            ->whereBetween('created_at', [
                $window->start->toDateTimeString(),
                $window->end->toDateTimeString(),
            ])
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('d')
            ->get();
    }
}
