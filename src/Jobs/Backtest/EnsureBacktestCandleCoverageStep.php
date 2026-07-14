<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Backtest;

use Carbon\Carbon;
use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Support\Backtest\CandleCoverageVerifier;
use Kraite\Core\Support\Backtest\CoverageGate;
use StepDispatcher\Models\Step;

/**
 * EnsureBacktestCandleCoverageStep
 *
 * Orchestrator for the risk-gated backtest data pipeline. On compute():
 *  - audits current coverage; if it already passes the gate, records the
 *    verdict in its own `response` and completes as an orphan (no children);
 *  - otherwise self-elects to parent mode (buildChildChainOnce — only here, only
 *    when children are actually created, to avoid the documented zombie
 *    pattern) and fans out a sequential child block:
 *      index 1  FetchVisionCandlesStep   (bulk completed months, Binance)
 *      index 2  FetchRestCandlesStep     (forward-fill + gap-fill, Binance)
 *      index 3  FetchTaapiCandlesStep    (recency top-up / non-Binance source)
 *      index 4  VerifyCoverageResultStep (re-audit + record the gate verdict)
 *    The dispatcher completes this parent once all four children conclude.
 *
 * The fetch steps are best-effort (they catch + complete), so a single source
 * hiccup never stalls the block — VerifyCoverageResultStep always runs and is
 * the authority on readiness.
 */
final class EnsureBacktestCandleCoverageStep extends BaseQueueableJob
{
    public ExchangeSymbol $exchangeSymbol;

    public string $timeframe;

    public int $maxMonths;

    public ?int $gapLookbackTs;

    public function __construct(int $exchangeSymbolId, string $timeframe, int $maxMonths = 24, ?int $gapLookbackTs = null)
    {
        $this->exchangeSymbol = ExchangeSymbol::with('apiSystem')->findOrFail($exchangeSymbolId);
        $this->timeframe = $timeframe;
        $this->maxMonths = $maxMonths;
        $this->gapLookbackTs = $gapLookbackTs;
        $this->retries = 3;
    }

    public function relatable()
    {
        return $this->exchangeSymbol;
    }

    public function compute()
    {
        $coverage = (new CandleCoverageVerifier)->verify($this->exchangeSymbol, $this->timeframe);
        $gate = CoverageGate::evaluate($coverage, $this->timeframe);

        // Already good — no fetch needed. Complete inline; the verdict lives on
        // this step's own response (no children, no zombie).
        if ($gate['ready']) {
            return [
                'spawned' => false,
                'ready' => true,
                'reason' => null,
                'coverage' => $coverage,
                'verified_at' => Carbon::now('UTC')->toIso8601String(),
            ];
        }

        $args = ['exchangeSymbolId' => $this->exchangeSymbol->id, 'timeframe' => $this->timeframe];

        $this->buildChildChainOnce(function (string $childBlockUuid) use ($args): void {
            Step::create([
                'class' => FetchVisionCandlesStep::class,
                'queue' => 'indicators',
                'arguments' => $args + ['maxMonths' => $this->maxMonths],
                'block_uuid' => $childBlockUuid,
                'index' => 1,
            ]);

            Step::create([
                'class' => FetchRestCandlesStep::class,
                'queue' => 'indicators',
                'arguments' => $args + ['gapLookbackTs' => $this->gapLookbackTs],
                'block_uuid' => $childBlockUuid,
                'index' => 2,
            ]);

            Step::create([
                'class' => FetchTaapiCandlesStep::class,
                'queue' => 'indicators',
                'arguments' => $args,
                'block_uuid' => $childBlockUuid,
                'index' => 3,
            ]);

            Step::create([
                'class' => VerifyCoverageResultStep::class,
                'queue' => 'indicators',
                'arguments' => $args,
                'block_uuid' => $childBlockUuid,
                'index' => 4,
            ]);
        });

        return [
            'spawned' => true,
            'child_block_uuid' => $this->step->child_block_uuid,
            'pre_fetch_coverage' => $coverage,
        ];
    }
}
