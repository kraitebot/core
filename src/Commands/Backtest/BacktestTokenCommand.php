<?php

declare(strict_types=1);

namespace Kraite\Core\Commands\Backtest;

use Carbon\Carbon;
use InvalidArgumentException;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Support\Backtest\BacktestSimulator;
use Kraite\Core\Support\Backtest\CandleCoverageVerifier;
use StepDispatcher\Support\BaseCommand;
use Throwable;

/**
 * BacktestTokenCommand
 *
 * CLI wrapper around `BacktestSimulator` for ad-hoc per-token ladder
 * backtesting. Mirrors the option surface of the legacy martingalian
 * `debug:analyse-non-reboundables` but reads Kraite's `candles` table
 * and uses Kraite's unbounded-ladder calculators (so results align
 * with what the live trader would produce for the same config).
 *
 * Examples:
 *   php artisan kraite:backtest-token --exchange_symbol_id=123 --account_id=1
 *   php artisan kraite:backtest-token --token=SOL --exchange=binance --account_id=1
 *   php artisan kraite:backtest-token --token=SOL --exchange=binance --timeframe=4h --tp_percent=0.5
 *   php artisan kraite:backtest-token --exchange_symbol_id=123 --account_id=1 --multipliers=2,2,2.5,1.5 --skip_stop_loss
 *   php artisan kraite:backtest-token --exchange_symbol_id=123 --account_id=1 --candle="2025-10-10 04:00"
 */
final class BacktestTokenCommand extends BaseCommand
{
    protected $signature = 'kraite:backtest-token
                            {--exchange_symbol_id= : ExchangeSymbol id to backtest}
                            {--token= : Ticker (e.g. SOL) — used with --exchange when exchange_symbol_id not given}
                            {--exchange=binance : Exchange canonical when selecting by token}
                            {--quote=USDT : Quote currency when selecting by token}
                            {--account_id=1 : Account id for default TP / SL / leverage pull}
                            {--timeframe=1h : Candle timeframe (1h / 4h / 12h)}
                            {--margin=100 : Quote-currency margin for the virtual position}
                            {--leverage= : Override leverage (defaults to account position_leverage_long)}
                            {--total_limit_orders= : Override N (defaults to symbol total_limit_orders or 4)}
                            {--multipliers= : Comma-separated per-rung qty multipliers override (e.g. 2,2,2.5,1.5)}
                            {--tp_percent= : Override take-profit percent (defaults to account profit_percentage)}
                            {--gap_long_percent= : Override LONG gap % (defaults to symbol percentage_gap_long)}
                            {--gap_short_percent= : Override SHORT gap % (defaults to symbol percentage_gap_short)}
                            {--sl_percent= : Override SL percent (defaults to account stop_market_initial_percentage)}
                            {--skip_stop_loss : Skip SL evaluation even when last rung touched}
                            {--days_to_ignore=20 : Suppress displaying rows within the last N days (analysis still uses all data)}
                            {--limit_hit= : Only show rows where deepest rung reached >= this}
                            {--candle= : Run on a single candle only — "YYYY-MM-DD HH:MM"}
                            {--candles_back= : Limit the backtest window to the last N candles (tf-aware)}
                            {--since= : Limit the backtest window to candles >= this date (YYYY-MM-DD). Overrides --candles_back when set}
                            {--output : Display command output (silent by default)}';

    protected $description = 'Backtest the Kraite ladder against a symbol\'s candle history — per-candle LONG / SHORT outcomes + totals.';

    public function handle(): int
    {
        try {
            $symbol = $this->resolveSymbol();
            if (! $symbol) {
                return self::FAILURE;
            }

            $account = Account::findOrFail((int) $this->option('account_id'));

            $config = $this->buildConfig($symbol, $account);

            $simulator = new BacktestSimulator;
            $result = $simulator->simulate(
                symbol: $symbol,
                timeframe: $config['timeframe'],
                margin: $config['margin'],
                leverage: $config['leverage'],
                totalLimitOrders: $config['total_limit_orders'],
                multipliers: $config['multipliers'],
                tpPercent: $config['tp_percent'],
                gapLongPercent: $config['gap_long_percent'],
                gapShortPercent: $config['gap_short_percent'],
                slPercent: $config['sl_percent'],
                skipStopLoss: (bool) $this->option('skip_stop_loss'),
                daysToIgnore: (int) $this->option('days_to_ignore'),
                limitHit: $this->parseOptionalInt($this->option('limit_hit')),
                specificCandle: $this->parseCandleOption($this->option('candle')),
                since: $this->resolveWindowSince(
                    $config['timeframe'],
                    $this->option('since'),
                    $this->parseOptionalInt($this->option('candles_back')),
                ),
            );

            $this->renderResult($symbol, $result);

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Backtest failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Resolve the target ExchangeSymbol either from --exchange_symbol_id
     * or from (--token, --exchange, --quote).
     */
    private function resolveSymbol(): ?ExchangeSymbol
    {
        $symbolId = $this->option('exchange_symbol_id');

        if ($symbolId !== null && $symbolId !== '') {
            return ExchangeSymbol::find((int) $symbolId)
                ?? tap(null, fn () => $this->error("ExchangeSymbol #{$symbolId} not found."));
        }

        $token = $this->option('token');
        if (! $token) {
            $this->error('Either --exchange_symbol_id OR --token + --exchange must be provided.');

            return null;
        }

        $symbol = ExchangeSymbol::query()
            ->whereHas('symbol', fn ($q) => $q->where('token', mb_strtoupper($token)))
            ->whereHas('apiSystem', fn ($q) => $q->where('canonical', $this->option('exchange')))
            ->where('quote', $this->option('quote'))
            ->first();

        if (! $symbol) {
            $this->error(sprintf(
                'No ExchangeSymbol found for token=%s exchange=%s quote=%s',
                $token, $this->option('exchange'), $this->option('quote')
            ));

            return null;
        }

        return $symbol;
    }

    /**
     * Merge CLI overrides on top of symbol / account defaults to produce
     * the effective backtest config.
     *
     * @return array<string, mixed>
     */
    private function buildConfig(ExchangeSymbol $symbol, Account $account): array
    {
        $multipliers = $this->parseMultipliers($this->option('multipliers'));

        return [
            'timeframe' => (string) $this->option('timeframe'),
            'margin' => (string) $this->option('margin'),
            'leverage' => (int) ($this->option('leverage') ?? $account->position_leverage_long ?? 10),
            'total_limit_orders' => (int) ($this->option('total_limit_orders') ?? $symbol->total_limit_orders ?? 4),
            'multipliers' => $multipliers,
            'tp_percent' => (string) ($this->option('tp_percent') ?? $account->profit_percentage ?? '0.36'),
            'gap_long_percent' => $this->nullableString($this->option('gap_long_percent')),
            'gap_short_percent' => $this->nullableString($this->option('gap_short_percent')),
            'sl_percent' => (string) ($this->option('sl_percent') ?? $account->stop_market_initial_percentage ?? '15'),
        ];
    }

    private function parseMultipliers(?string $input): ?array
    {
        if ($input === null || mb_trim($input) === '') {
            return null;
        }

        $parts = array_filter(array_map('trim', explode(',', $input)), fn ($v) => $v !== '');

        foreach ($parts as $value) {
            if (! is_numeric($value) || (float) $value <= 0) {
                throw new InvalidArgumentException("Invalid multipliers entry '{$value}'. Must be positive numerics comma-separated.");
            }
        }

        return array_values(array_map('strval', $parts));
    }

    private function parseCandleOption(?string $input): ?Carbon
    {
        if ($input === null || mb_trim($input) === '') {
            return null;
        }

        return Carbon::parse($input, config('app.timezone', 'UTC'));
    }

    /**
     * Resolve the effective backtest-window start. Mirrors the UI's
     * BacktrackingController::resolveWindowSince so both entry points
     * honour the same precedence and semantics.
     */
    private function resolveWindowSince(string $timeframe, ?string $since, ?int $candlesBack): ?Carbon
    {
        if ($since !== null && mb_trim($since) !== '') {
            return Carbon::parse($since);
        }

        if ($candlesBack !== null) {
            if (! isset(CandleCoverageVerifier::INTERVAL_SECONDS[$timeframe])) {
                throw new InvalidArgumentException("Unsupported timeframe: {$timeframe}.");
            }

            return Carbon::now()->subSeconds($candlesBack * CandleCoverageVerifier::INTERVAL_SECONDS[$timeframe]);
        }

        return null;
    }

    private function parseOptionalInt(mixed $value): ?int
    {
        return ($value === null || $value === '') ? null : (int) $value;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function renderResult(ExchangeSymbol $symbol, array $result): void
    {
        $totals = $result['totals'];
        $rows = $result['rows'];
        $meta = $result['meta'];

        $this->line('');
        $this->info('PAIR: '.$symbol->parsed_trading_pair.' | TF: '.$meta['timeframe']);
        $this->line(str_repeat('-', 60));

        if (empty($rows)) {
            $this->warn($meta['reason'] ?? 'No rows to display (all filtered / suppressed).');
        } else {
            $this->table(
                ['direction', 'start', 'entry', 'last_rung', 'last_touch', 'tp_price', 'tp_hit', 'candles_to_profit', 'status', 'note'],
                array_map(fn ($r) => [
                    $r['direction'],
                    $r['start_candle'],
                    $r['entry_ref_price'],
                    (string) $r['last_rung'],
                    (string) ($r['last_touch_candle'] ?? 'N/A'),
                    (string) $r['tp_price'],
                    (string) ($r['tp_hit_candle'] ?? 'N/A'),
                    (string) ($r['candles_to_profit'] ?? '—'),
                    $r['status'],
                    $r['message'] ?? '',
                ], $rows)
            );
        }

        // Each start candle fires two simulations (LONG + SHORT), so the
        // percentage denominator is 2× the candle count to reflect the
        // total outcome space — otherwise a 50/50 LONG-only week shows
        // "100%" for TP-market-only which is misleading.
        $candles = (int) $totals['candles'];
        $sims = $candles * 2;
        $pct = fn (int $n): string => $sims > 0 ? number_format(($n / $sims) * 100, 2) : '0.00';

        $this->line('');
        $this->info('Totals:');
        $this->line(sprintf('  Analysed candles: %d (2 sims per candle — LONG + SHORT = %d simulations)', $candles, $sims));
        $this->line(sprintf('  TP hit from market-only: %d (%s%%)', $totals['tp_market_only'], $pct((int) $totals['tp_market_only'])));
        $this->line(sprintf('  Reboundable: %d (%s%%)', $totals['reboundable'], $pct((int) $totals['reboundable'])));
        $this->line(sprintf('  Stopped out: %d (%s%%)', $totals['stops'], $pct((int) $totals['stops'])));
        $this->line(sprintf('  Non-reboundable: %d (%s%%)', $totals['non_reboundable'], $pct((int) $totals['non_reboundable'])));
        if (! empty($totals['dropped_inconclusive'])) {
            $this->line(sprintf('  Dropped (last 15d buffer, non-rebound false positives): %d', $totals['dropped_inconclusive']));
        }
        $this->line('');
        $this->line(sprintf('[ Candles in DB for %s / %s: %d ]', $symbol->parsed_trading_pair, $meta['timeframe'], $meta['total_candles_in_db'] ?? 0));
        $this->line('');
    }
}
