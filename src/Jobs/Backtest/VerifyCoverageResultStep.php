<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Backtest;

use Carbon\Carbon;
use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Support\Backtest\CandleCoverageVerifier;
use Kraite\Core\Support\Backtest\CoverageGate;

/**
 * VerifyCoverageResultStep
 *
 * Final step of the ensure-coverage block. Runs after the fetch steps and
 * re-audits the candle table, then records the risk-gate verdict in its own
 * `response` column. The admin poll endpoint reads this to decide whether the
 * grade may be shown. This is the dispatcher-side mirror of the server-side
 * gate in BacktrackingController::run().
 */
final class VerifyCoverageResultStep extends BaseQueueableJob
{
    public ExchangeSymbol $exchangeSymbol;

    public string $timeframe;

    public int $maxMonths;

    public ?int $requestedSinceTimestamp;

    public function __construct(
        int $exchangeSymbolId,
        string $timeframe,
        int $maxMonths = 24,
        ?int $requestedSinceTimestamp = null,
    ) {
        $this->exchangeSymbol = ExchangeSymbol::with('apiSystem')->findOrFail($exchangeSymbolId);
        $this->timeframe = $timeframe;
        $this->maxMonths = $maxMonths;
        $this->requestedSinceTimestamp = $requestedSinceTimestamp;
        $this->retries = 2;
    }

    public function relatable(): ExchangeSymbol
    {
        return $this->exchangeSymbol;
    }

    /**
     * @return array{ready: bool, reason: string|null, coverage: array<string, mixed>, verified_at: string}
     */
    public function compute(): array
    {
        $coverage = (new CandleCoverageVerifier)->verify($this->exchangeSymbol, $this->timeframe);
        $gate = CoverageGate::evaluate(
            $coverage,
            $this->timeframe,
            $this->maxMonths,
            $this->requestedSinceTimestamp,
        );

        return [
            'ready' => $gate['ready'],
            'reason' => $gate['reason'],
            'coverage' => $coverage,
            'verified_at' => Carbon::now('UTC')->toIso8601String(),
        ];
    }
}
