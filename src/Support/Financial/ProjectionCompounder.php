<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Financial;

use Carbon\CarbonImmutable;
use Kraite\Core\Enums\ProjectionFormat;
use Kraite\Core\Enums\ProjectionScenario;

/**
 * Pure compounding helper shared by AccountFinancials and FleetFinancials.
 * Given a scenarios bundle, the realized result so far, the live wallet,
 * the window, and a format, returns the combined "realized + projected to
 * window end" figure. No DB access — caller pre-resolves the inputs.
 *
 * Both halves are cash-flow-proof by construction. The realized half is
 * trade PnL (euros) or the window's time-weighted return (percent); the
 * projected half only ever measures how much the live wallet grows from
 * today onwards. Nothing anywhere compares a post-transfer balance to a
 * pre-transfer one, so paying money in raises the euros on the table
 * without inventing a percentage the trader never earned.
 */
final class ProjectionCompounder
{
    /**
     * @param  array{pessimistic_pct: ?string, neutral_pct: ?string, optimistic_pct: ?string, days_observed: int}  $scenarios
     * @param  ?float  $realizedPct  Window-to-date time-weighted return, in percent.
     * @param  ?string  $realizedAmount  Window-to-date realized trade PnL, in quote currency.
     */
    public static function compute(
        array $scenarios,
        ?string $currentWallet,
        ?float $realizedPct,
        ?string $realizedAmount,
        ProjectionScenario $scenario,
        Window $window,
        ProjectionFormat $format,
        int $scale = 8,
    ): float|string|null {
        $key = $scenario->value.'_pct';
        $dailyPct = $scenarios[$key] ?? null;

        if ($dailyPct === null || $currentWallet === null) {
            return null;
        }

        if (bccomp($currentWallet, '0', $scale) <= 0) {
            return null;
        }

        $endWallet = self::compoundWallet(
            currentWallet: $currentWallet,
            dailyPct: $dailyPct,
            from: CarbonImmutable::now(),
            until: $window->end,
            scale: $scale,
        );

        if ($format === ProjectionFormat::Amount) {
            $forwardGain = bcsub($endWallet, $currentWallet, $scale);

            return bcadd($realizedAmount ?? '0', $forwardGain, $scale);
        }

        // Chain what the window already delivered with what the rest of it
        // should add — the same geometric linking the realized rates use.
        // `sprintf` rather than a plain string cast: PHP renders a small float
        // such as 0.000001 as "1.0E-6", which bcmath rejects outright.
        $realizedFactor = bcadd(
            '1',
            bcdiv(sprintf('%.12F', $realizedPct ?? 0.0), '100', 16),
            16,
        );
        $forwardFactor = bcdiv($endWallet, $currentWallet, 16);
        $windowFactor = bcmul($realizedFactor, $forwardFactor, 16);

        return (float) bcmul(bcsub($windowFactor, '1', 16), '100', 6);
    }

    /**
     * Walk the wallet forward day-by-day at `dailyPct` from `from` to
     * `until`. Compounds discretely (one multiply per day) so the
     * result matches what the admin projections calendar paints cell
     * by cell — no `pow()` rounding drift.
     */
    public static function compoundWallet(
        string $currentWallet,
        string $dailyPct,
        CarbonImmutable $from,
        CarbonImmutable $until,
        int $scale = 8,
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
