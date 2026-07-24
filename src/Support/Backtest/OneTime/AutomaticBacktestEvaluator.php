<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Backtest\OneTime;

use Illuminate\Support\Facades\DB;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Support\Backtest\BacktestSimulator;
use Kraite\Core\Support\Backtest\CandleCoverageVerifier;
use Kraite\Core\Support\Backtest\CoverageGate;

final class AutomaticBacktestEvaluator
{
    private const FIXED_MULTIPLIERS = ['2', '2', '2', '2'];

    private const FIXED_TOTAL_LIMIT_ORDERS = 4;

    private const FIXED_LEVERAGE = 20;

    private const FIXED_MARGIN = '5000';

    private const TIMEFRAME = '1d';

    public function __construct(
        private readonly BacktestSimulator $simulator,
        private readonly CandleCoverageVerifier $coverageVerifier,
        private readonly AutomaticBacktestPolicy $policy,
    ) {}

    /**
     * @return array{
     *   exchange_symbol_id: int,
     *   pair: string,
     *   timeframe: string,
     *   configuration: array<string, mixed>,
     *   coverage: array<string, mixed>,
     *   coverage_gate: array<string, mixed>,
     *   totals: array<string, mixed>,
     *   resolved_simulations: int,
     *   eligible: bool,
     *   applied: bool,
     *   decision: string,
     *   reason_codes: array<int, string>,
     *   reasons: array<int, string>
     * }
     */
    public function evaluate(ExchangeSymbol $symbol, Account $account, bool $apply): array
    {
        $symbol = ExchangeSymbol::with('apiSystem')->findOrFail($symbol->id);
        $account = Account::findOrFail($account->id);
        $configuration = $this->configuration($symbol, $account);
        $coverage = $this->coverageVerifier->verify($symbol, self::TIMEFRAME);
        $coverageGate = CoverageGate::evaluate($coverage, self::TIMEFRAME);
        $configurationMatches = $this->configurationMatches($symbol, $configuration);

        $simulation = $this->canSimulate($configuration)
            ? $this->simulator->simulate(
                symbol: $symbol,
                timeframe: self::TIMEFRAME,
                margin: self::FIXED_MARGIN,
                leverage: self::FIXED_LEVERAGE,
                totalLimitOrders: self::FIXED_TOTAL_LIMIT_ORDERS,
                multipliers: self::FIXED_MULTIPLIERS,
                tpPercent: $configuration['tp_percent'],
                gapLongPercent: $configuration['gap_long_percent'],
                gapShortPercent: $configuration['gap_short_percent'],
                slPercent: $configuration['sl_percent'],
                skipStopLoss: false,
                daysToIgnore: 0,
                limitHit: null,
                specificCandle: null,
                since: null,
            )
            : ['totals' => $this->emptyTotals()];

        $assessment = $this->policy->assess(
            isPending: $this->isPending($symbol),
            isBinance: $symbol->apiSystem->canonical === 'binance',
            isLinked: $symbol->symbol_id !== null,
            configurationMatches: $configurationMatches,
            coverageGate: $coverageGate,
            totals: $simulation['totals'],
        );

        $applied = false;
        if ($apply && $assessment['eligible']) {
            ['applied' => $applied, 'assessment' => $assessment] = $this->applyApproval(
                symbol: $symbol,
                account: $account,
                configuration: $configuration,
                coverageGate: $coverageGate,
                totals: $simulation['totals'],
            );
        }

        $decision = match (true) {
            $applied => 'approved',
            $this->hasReason($assessment, 'already_reviewed') => 'already_reviewed',
            $assessment['eligible'] => 'would_approve',
            default => 'manual_review',
        };

        return [
            'exchange_symbol_id' => $symbol->id,
            'pair' => mb_strtoupper($symbol->token.$symbol->quote),
            'timeframe' => self::TIMEFRAME,
            'configuration' => $configuration,
            'coverage' => $coverage,
            'coverage_gate' => $coverageGate,
            'totals' => $simulation['totals'],
            'resolved_simulations' => $assessment['resolved_simulations'],
            'eligible' => $assessment['eligible'],
            'applied' => $applied,
            'decision' => $decision,
            'reason_codes' => $assessment['reason_codes'],
            'reasons' => $assessment['reasons'],
        ];
    }

    /**
     * @param  array<string, string|null|int|array<int, string>>  $configuration
     * @param  array<string, mixed>  $coverageGate
     * @param  array<string, mixed>  $totals
     * @return array{
     *   applied: bool,
     *   assessment: array{
     *     eligible: bool,
     *     resolved_simulations: int,
     *     reason_codes: array<int, string>,
     *     reasons: array<int, string>
     *   }
     * }
     */
    private function applyApproval(
        ExchangeSymbol $symbol,
        Account $account,
        array $configuration,
        array $coverageGate,
        array $totals,
    ): array {
        return DB::transaction(function () use ($symbol, $account, $configuration, $coverageGate, $totals): array {
            $lockedSymbol = ExchangeSymbol::with('apiSystem')
                ->whereKey($symbol->id)
                ->lockForUpdate()
                ->firstOrFail();
            $freshAccount = Account::findOrFail($account->id);
            $freshConfiguration = $this->configuration($lockedSymbol, $freshAccount);
            $configurationUnchanged = $freshConfiguration === $configuration;

            $assessment = $this->policy->assess(
                isPending: $this->isPending($lockedSymbol),
                isBinance: $lockedSymbol->apiSystem->canonical === 'binance',
                isLinked: $lockedSymbol->symbol_id !== null,
                configurationMatches: $configurationUnchanged
                    && $this->configurationMatches($lockedSymbol, $freshConfiguration),
                coverageGate: $coverageGate,
                totals: $totals,
            );

            if (! $assessment['eligible']) {
                return ['applied' => false, 'assessment' => $assessment];
            }

            $lockedSymbol->forceFill([
                'was_backtesting_approved' => true,
                'backtesting_review_status' => 'approved',
                'is_manually_enabled' => true,
                'percentage_gap_long' => $configuration['gap_long_percent'],
                'percentage_gap_short' => $configuration['gap_short_percent'],
                'profit_percentage' => $configuration['tp_percent'],
                'stop_market_percentage' => $configuration['sl_percent'],
            ])->save();

            $this->syncTestedConfigurationToSiblings($lockedSymbol, $configuration);

            return ['applied' => true, 'assessment' => $assessment];
        });
    }

    /**
     * @param  array<string, string|null|int|array<int, string>>  $configuration
     */
    private function syncTestedConfigurationToSiblings(ExchangeSymbol $symbol, array $configuration): void
    {
        foreach ($symbol->getOthersFromExchanges() as $sibling) {
            $sibling->forceFill([
                'percentage_gap_long' => $configuration['gap_long_percent'],
                'percentage_gap_short' => $configuration['gap_short_percent'],
                'profit_percentage' => $configuration['tp_percent'],
                'stop_market_percentage' => $configuration['sl_percent'],
            ])->saveQuietly();
        }
    }

    /**
     * @return array{
     *   timeframe: string,
     *   margin: string,
     *   leverage: int,
     *   total_limit_orders: int,
     *   multipliers: array<int, string>,
     *   tp_percent: string,
     *   gap_long_percent: string|null,
     *   gap_short_percent: string|null,
     *   sl_percent: string
     * }
     */
    private function configuration(ExchangeSymbol $symbol, Account $account): array
    {
        return [
            'timeframe' => self::TIMEFRAME,
            'margin' => self::FIXED_MARGIN,
            'leverage' => self::FIXED_LEVERAGE,
            'total_limit_orders' => self::FIXED_TOTAL_LIMIT_ORDERS,
            'multipliers' => self::FIXED_MULTIPLIERS,
            'tp_percent' => $this->decimalAttribute($account, 'profit_percentage'),
            'gap_long_percent' => $symbol->percentage_gap_long !== null
                ? (string) $symbol->percentage_gap_long
                : null,
            'gap_short_percent' => $symbol->percentage_gap_short !== null
                ? (string) $symbol->percentage_gap_short
                : null,
            'sl_percent' => $this->decimalAttribute($account, 'stop_market_initial_percentage'),
        ];
    }

    /**
     * @param  array<string, mixed>  $configuration
     */
    private function configurationMatches(ExchangeSymbol $symbol, array $configuration): bool
    {
        $effectiveMultipliers = $symbol->limit_quantity_multipliers ?? self::FIXED_MULTIPLIERS;

        if (count($effectiveMultipliers) !== count(self::FIXED_MULTIPLIERS)) {
            return false;
        }

        foreach ($effectiveMultipliers as $multiplier) {
            if (! is_numeric($multiplier) || (float) $multiplier !== 2.0) {
                return false;
            }
        }

        return (int) ($symbol->total_limit_orders ?? self::FIXED_TOTAL_LIMIT_ORDERS) === self::FIXED_TOTAL_LIMIT_ORDERS
            && $this->canSimulate($configuration);
    }

    /**
     * @param  array<string, mixed>  $configuration
     */
    private function canSimulate(array $configuration): bool
    {
        foreach (['tp_percent', 'gap_long_percent', 'gap_short_percent', 'sl_percent'] as $key) {
            if (! is_numeric($configuration[$key] ?? null) || (float) $configuration[$key] <= 0) {
                return false;
            }
        }

        return true;
    }

    private function isPending(ExchangeSymbol $symbol): bool
    {
        return $symbol->getAttribute('was_backtesting_approved') === false
            && $symbol->getAttribute('backtesting_review_status') === null;
    }

    private function decimalAttribute(Account $account, string $attribute): string
    {
        $value = $account->getAttribute($attribute);

        return is_string($value) || is_int($value) || is_float($value)
            ? (string) $value
            : '';
    }

    /**
     * @param  array<string, mixed>  $assessment
     */
    private function hasReason(array $assessment, string $reason): bool
    {
        $reasonCodes = $assessment['reason_codes'] ?? null;

        return is_array($reasonCodes) && in_array($reason, $reasonCodes, true);
    }

    /**
     * @return array{candles: int, stops: int, tp_market_only: int, reboundable: int, inconclusive: int, skipped: int}
     */
    private function emptyTotals(): array
    {
        return [
            'candles' => 0,
            'stops' => 0,
            'tp_market_only' => 0,
            'reboundable' => 0,
            'inconclusive' => 0,
            'skipped' => 0,
        ];
    }
}
