<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Backtest\OneTime;

final class AutomaticBacktestPolicy
{
    public const MAX_EXCLUSIVE_STOPS = 5;

    public const MINIMUM_START_CANDLES = 180;

    /**
     * @param  array<string, mixed>  $coverageGate
     * @param  array<string, mixed>  $totals
     * @return array{eligible: bool, resolved_simulations: int, reason_codes: array<int, string>, reasons: array<int, string>}
     */
    public function assess(
        bool $isPending,
        bool $isBinance,
        bool $isLinked,
        bool $configurationMatches,
        array $coverageGate,
        array $totals,
    ): array {
        $reasonCodes = [];

        if (! $isPending) {
            $reasonCodes[] = 'already_reviewed';
        }

        if (! $isBinance) {
            $reasonCodes[] = 'not_binance';
        }

        if (! $isLinked) {
            $reasonCodes[] = 'unlinked_token';
        }

        if (! $configurationMatches) {
            $reasonCodes[] = 'configuration_mismatch';
        }

        if (($coverageGate['ready'] ?? false) !== true) {
            $reasonCodes[] = 'coverage_not_ready';
        }

        if ($this->integer($totals['candles'] ?? null) < self::MINIMUM_START_CANDLES) {
            $reasonCodes[] = 'insufficient_sample';
        }

        $resolvedSimulations = $this->integer($totals['stops'] ?? null)
            + $this->integer($totals['tp_market_only'] ?? null)
            + $this->integer($totals['reboundable'] ?? null);

        if ($resolvedSimulations === 0) {
            $reasonCodes[] = 'no_resolved_simulations';
        }

        if ($this->integer($totals['skipped'] ?? null) !== 0) {
            $reasonCodes[] = 'skipped_simulations';
        }

        if ($this->integer($totals['stops'] ?? null) >= self::MAX_EXCLUSIVE_STOPS) {
            $reasonCodes[] = 'stop_threshold_reached';
        }

        $messages = [
            'already_reviewed' => 'Token already has a backtesting decision.',
            'not_binance' => 'Automatic grading only accepts the canonical Binance symbol.',
            'unlinked_token' => 'Symbol is not linked to a canonical token.',
            'configuration_mismatch' => 'Live ladder differs from the tested 4 × 2 envelope or has invalid percentages.',
            'coverage_not_ready' => 'Daily candles are stale or not contiguous.',
            'insufficient_sample' => 'Fewer than 180 eligible daily start candles were simulated.',
            'no_resolved_simulations' => 'No simulation reached take-profit or stop-loss.',
            'skipped_simulations' => 'At least one simulation was skipped by sizing or ladder validation.',
            'stop_threshold_reached' => 'Stop-loss count is 5 or greater.',
        ];

        return [
            'eligible' => $reasonCodes === [],
            'resolved_simulations' => $resolvedSimulations,
            'reason_codes' => $reasonCodes,
            'reasons' => array_map(
                static fn (string $reasonCode): string => $messages[$reasonCode],
                $reasonCodes,
            ),
        ];
    }

    private function integer(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
