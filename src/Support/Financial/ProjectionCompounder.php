<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Financial;

use Carbon\CarbonImmutable;
use Kraite\Core\Enums\ProjectionFormat;
use Kraite\Core\Enums\ProjectionScenario;

/**
 * Pure compounding helper shared by AccountFinancials and FleetFinancials.
 * Given a scenarios bundle, the start/current wallets, the window, and
 * a format, returns the combined "realized + projected to window end"
 * result. No DB access — caller pre-resolves the inputs.
 */
final class ProjectionCompounder
{
    /**
     * @param  array{pessimistic_pct: ?string, neutral_pct: ?string, optimistic_pct: ?string, days_observed: int}  $scenarios
     */
    public static function compute(
        array $scenarios,
        ?string $startWallet,
        ?string $currentWallet,
        ProjectionScenario $scenario,
        Window $window,
        ProjectionFormat $format,
        int $scale = 8,
    ): float|string|null {
        $key = $scenario->value.'_pct';
        $dailyPct = $scenarios[$key] ?? null;

        if ($dailyPct === null || $startWallet === null || $currentWallet === null) {
            return null;
        }

        if (bccomp($startWallet, '0', $scale) <= 0) {
            return null;
        }

        $endWallet = self::compoundForward(
            currentWallet: $currentWallet,
            dailyPct: $dailyPct,
            from: CarbonImmutable::now(),
            until: $window->end,
            scale: $scale,
        );

        $totalDelta = bcsub($endWallet, $startWallet, $scale);

        if ($format === ProjectionFormat::Amount) {
            return $totalDelta;
        }

        $ratio = bcdiv($totalDelta, $startWallet, $scale);

        return (float) bcmul($ratio, '100', 6);
    }

    /**
     * Walk the wallet forward day-by-day at `dailyPct` from `from` to
     * `until`. Compounds discretely (one multiply per day) so the
     * result matches what the admin projections calendar paints cell
     * by cell — no `pow()` rounding drift.
     */
    private static function compoundForward(
        string $currentWallet,
        string $dailyPct,
        CarbonImmutable $from,
        CarbonImmutable $until,
        int $scale,
    ): string {
        $wallet = $currentWallet;

        if ($until->lte($from)) {
            return $wallet;
        }

        $cursor = $from->startOfDay();
        $stop = $until->startOfDay();
        $multiplier = bcadd('1', $dailyPct, 16);

        while ($cursor->lt($stop)) {
            $wallet = bcmul($wallet, $multiplier, $scale);
            $cursor = $cursor->addDay();
        }

        return $wallet;
    }
}
