<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Backtest;

use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Support\Backtest\BinanceVisionCandleFetcher;
use Throwable;

/**
 * FetchVisionCandlesStep
 *
 * Dispatchable wrapper around BinanceVisionCandleFetcher (bulk completed-month
 * archives, Binance only). Best-effort by design: any failure is caught and
 * returned as a payload so the step still COMPLETES — the block must always
 * reach the final VerifyCoverageResultStep, which (with the run-time gate) is
 * the authority on whether the data is good enough to grade.
 */
final class FetchVisionCandlesStep extends BaseQueueableJob
{
    public ExchangeSymbol $exchangeSymbol;

    public string $timeframe;

    public int $maxMonths;

    public function __construct(int $exchangeSymbolId, string $timeframe, int $maxMonths = 24)
    {
        $this->exchangeSymbol = ExchangeSymbol::with('apiSystem')->findOrFail($exchangeSymbolId);
        $this->timeframe = $timeframe;
        $this->maxMonths = $maxMonths;
        $this->retries = 3;
    }

    public function relatable()
    {
        return $this->exchangeSymbol;
    }

    public function compute()
    {
        if (mb_strtolower((string) ($this->exchangeSymbol->apiSystem->canonical ?? '')) !== 'binance') {
            return ['skipped' => true, 'reason' => 'non-Binance symbol — Vision has no data'];
        }

        try {
            return (new BinanceVisionCandleFetcher)->fetch($this->exchangeSymbol, $this->timeframe, $this->maxMonths);
        } catch (Throwable $e) {
            // Best-effort: never fail the block on a Vision hiccup. REST + TAAPI
            // still run; Verify reports the real coverage.
            return ['error' => mb_substr($e->getMessage(), 0, 300), 'source' => 'vision'];
        }
    }
}
