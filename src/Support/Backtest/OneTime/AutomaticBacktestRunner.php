<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Backtest\OneTime;

use Kraite\Core\Jobs\Backtest\FetchRestCandlesStep;
use Kraite\Core\Jobs\Backtest\FetchTaapiCandlesStep;
use Kraite\Core\Jobs\Backtest\FetchVisionCandlesStep;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Support\Backtest\CandleCoverageVerifier;
use Kraite\Core\Support\Backtest\CoverageGate;

/**
 * @phpstan-type AutomaticBacktestRun array{
 *   pair: string,
 *   fetch_attempted: bool,
 *   fetch_reports: array<string, mixed>,
 *   coverage_before: array<string, mixed>,
 *   coverage_after: array<string, mixed>,
 *   coverage_gate: array{ready: bool, fresh: bool, complete: bool, has_data: bool, reason: string|null},
 *   backtest: array{
 *     exchange_symbol_id: int,
 *     pair: string,
 *     timeframe: string,
 *     configuration: array<string, mixed>,
 *     coverage: array<string, mixed>,
 *     coverage_gate: array<string, mixed>,
 *     totals: array<string, mixed>,
 *     resolved_simulations: int,
 *     eligible: bool,
 *     applied: bool,
 *     decision: string,
 *     reason_codes: array<int, string>,
 *     reasons: array<int, string>
 *   }
 * }
 */
final class AutomaticBacktestRunner
{
    private const MINIMUM_STORED_DAILY_CANDLES = 195;

    public function __construct(
        private readonly CandleCoverageVerifier $coverageVerifier,
        private readonly AutomaticBacktestEvaluator $evaluator,
    ) {}

    /**
     * @return AutomaticBacktestRun
     */
    public function run(
        ExchangeSymbol $symbol,
        Account $account,
        bool $apply,
        int $maxMonths = 24,
    ): array {
        $coverageBefore = $this->coverageVerifier->verify($symbol, '1d');
        $gateBefore = CoverageGate::evaluate($coverageBefore, '1d');
        $fetchAttempted = ! $gateBefore['ready']
            || (int) $coverageBefore['total_present'] < self::MINIMUM_STORED_DAILY_CANDLES;
        $fetchReports = [];

        if ($fetchAttempted) {
            $fetchReports = [
                'vision' => (new FetchVisionCandlesStep($symbol->id, '1d', $maxMonths))->compute(),
                'rest' => (new FetchRestCandlesStep($symbol->id, '1d'))->compute(),
                'taapi' => (new FetchTaapiCandlesStep($symbol->id, '1d'))->compute(),
            ];
        }

        $coverageAfter = $this->coverageVerifier->verify($symbol, '1d');
        $gateAfter = CoverageGate::evaluate($coverageAfter, '1d');
        $backtest = $this->evaluator->evaluate($symbol, $account, $apply);

        return [
            'pair' => mb_strtoupper($symbol->token.$symbol->quote),
            'fetch_attempted' => $fetchAttempted,
            'fetch_reports' => $fetchReports,
            'coverage_before' => $coverageBefore,
            'coverage_after' => $coverageAfter,
            'coverage_gate' => $gateAfter,
            'backtest' => $backtest,
        ];
    }
}
