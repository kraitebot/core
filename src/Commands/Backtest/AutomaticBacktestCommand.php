<?php

declare(strict_types=1);

namespace Kraite\Core\Commands\Backtest;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Kraite\Core\Jobs\Backtest\OneTime\DispatchAutomaticBacktestsStep;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Support\Backtest\OneTime\AutomaticBacktestRunner;
use StepDispatcher\Models\Step;
use StepDispatcher\Support\BaseCommand;
use Throwable;

/** @phpstan-import-type AutomaticBacktestRun from AutomaticBacktestRunner */
final class AutomaticBacktestCommand extends BaseCommand
{
    protected $signature = 'kraite:backtest
                            {token? : Binance pair, for example TRXUSDT}
                            {--account_id=1 : Account providing default TP and SL}
                            {--apply : Persist approval when every automatic gate passes}
                            {--all-pending : Dispatch the one-time pending-token batch}
                            {--concurrency=1 : Tokens processed in each batch wave}
                            {--max-months=24 : Candle history requested by the coverage pipeline}
                            {--limit= : Optional batch token limit}';

    protected $description = 'One-time 1D default-config backtest and guarded automatic approval workflow.';

    public function handle(AutomaticBacktestRunner $runner): int
    {
        try {
            $token = $this->argument('token');
            $allPending = (bool) $this->option('all-pending');

            if ($allPending) {
                if (is_string($token) && $token !== '') {
                    $this->error('Use either one token or --all-pending, not both.');

                    return self::FAILURE;
                }

                return $this->dispatchBatch();
            }

            if (! is_string($token) || mb_trim($token) === '') {
                $this->error('Provide a Binance pair or use --all-pending.');

                return self::FAILURE;
            }

            $symbol = $this->resolveBinancePair($token);
            if ($symbol === null) {
                $this->error("Binance pair {$token} was not found.");

                return self::FAILURE;
            }

            $account = Account::findOrFail((int) $this->option('account_id'));
            $maxMonths = max(1, min(48, (int) $this->option('max-months')));
            $result = $runner->run(
                symbol: $symbol,
                account: $account,
                apply: (bool) $this->option('apply'),
                maxMonths: $maxMonths,
            );
            $this->renderResult($result);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Automatic backtest failed: '.$exception->getMessage());

            return self::FAILURE;
        }
    }

    private function dispatchBatch(): int
    {
        $account = Account::findOrFail((int) $this->option('account_id'));
        $concurrency = max(1, min(20, (int) $this->option('concurrency')));
        $maxMonths = max(1, min(48, (int) $this->option('max-months')));
        $limitOption = $this->option('limit');
        $limit = is_numeric($limitOption) ? max(1, (int) $limitOption) : null;
        $lock = Cache::lock('kraite:one-time-automatic-backtest-dispatch', 10);

        if (! $lock->get()) {
            $this->error('Another automatic-backtest dispatch is being prepared.');

            return self::FAILURE;
        }

        try {
            if (Step::query()->forClasses(DispatchAutomaticBacktestsStep::class)->nonTerminal()->exists()) {
                $this->error('An automatic-backtest batch is already active.');

                return self::FAILURE;
            }

            $step = Step::create([
                'class' => DispatchAutomaticBacktestsStep::class,
                'queue' => 'indicators',
                'relatable_type' => Account::class,
                'relatable_id' => $account->id,
                'arguments' => [
                    'accountId' => $account->id,
                    'apply' => (bool) $this->option('apply'),
                    'concurrency' => $concurrency,
                    'maxMonths' => $maxMonths,
                    'limit' => $limit,
                ],
                'block_uuid' => (string) Str::uuid(),
                'index' => 1,
            ]);
        } finally {
            $lock->release();
        }

        $mode = $this->option('apply') ? 'approval enabled' : 'preview only';
        $this->info("Batch step #{$step->id} dispatched ({$mode}, concurrency {$concurrency}).");

        return self::SUCCESS;
    }

    private function resolveBinancePair(string $input): ?ExchangeSymbol
    {
        $pair = mb_strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', $input));
        $query = ExchangeSymbol::query()
            ->whereHas(
                'apiSystem',
                static fn (Builder $apiSystems): Builder => $apiSystems->where('canonical', 'binance'),
            );

        $byAsset = (clone $query)->whereRaw('UPPER(asset) = ?', [$pair])->first();
        if ($byAsset !== null) {
            return $byAsset;
        }

        foreach (['USDT', 'USDC', 'BUSD', 'USD', 'BTC', 'ETH'] as $quote) {
            if (! str_ends_with($pair, $quote) || mb_strlen($pair) <= mb_strlen($quote)) {
                continue;
            }

            $token = mb_substr($pair, 0, -mb_strlen($quote));

            return (clone $query)
                ->where('token', $token)
                ->where('quote', $quote)
                ->first();
        }

        return null;
    }

    /** @param AutomaticBacktestRun $result */
    private function renderResult(array $result): void
    {
        $backtest = $result['backtest'];
        $label = match ($backtest['decision']) {
            'approved' => 'APPROVED',
            'would_approve' => 'WOULD APPROVE',
            'already_reviewed' => 'ALREADY REVIEWED',
            default => 'MANUAL REVIEW',
        };
        $coverageReady = $result['coverage_gate']['ready'];

        $this->info("{$result['pair']} · 1D · {$label}");
        $this->line('Candle fetch attempted: '.($result['fetch_attempted'] ? 'yes' : 'no'));
        $this->line('Coverage: '.($coverageReady ? 'ready' : 'not ready'));
        $this->table(
            ['Starts', 'Resolved', 'Stops', 'Skipped'],
            [[
                $this->integer($backtest['totals']['candles'] ?? null),
                $backtest['resolved_simulations'],
                $this->integer($backtest['totals']['stops'] ?? null),
                $this->integer($backtest['totals']['skipped'] ?? null),
            ]],
        );

        foreach ($backtest['reasons'] as $reason) {
            $this->warn("Pending: {$reason}");
        }
    }

    private function integer(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
