<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Backtest;

use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Support\Backtest\TaapiCandlesFetcher;
use Throwable;

/**
 * FetchTaapiCandlesStep
 *
 * Dispatchable wrapper around TaapiCandlesFetcher — the recency top-up that
 * fills what Binance Vision/REST can't, and the ONLY source for non-Binance
 * symbols. Runs last in the block; for Binance it usually no-ops because the
 * fetcher skips when the DB already holds the latest closed candle (gap=0).
 * Best-effort (see FetchVisionCandlesStep) — always completes.
 */
final class FetchTaapiCandlesStep extends BaseQueueableJob
{
    public ExchangeSymbol $exchangeSymbol;

    public string $timeframe;

    public function __construct(int $exchangeSymbolId, string $timeframe)
    {
        $this->exchangeSymbol = ExchangeSymbol::with('apiSystem')->findOrFail($exchangeSymbolId);
        $this->timeframe = $timeframe;
        $this->retries = 3;
    }

    public function relatable(): ExchangeSymbol
    {
        return $this->exchangeSymbol;
    }

    /** @return array<string, mixed> */
    public function compute(): array
    {
        try {
            return (new TaapiCandlesFetcher)->fetch(
                $this->exchangeSymbol,
                $this->timeframe,
                200,
                0,
            );
        } catch (Throwable $e) {
            return ['error' => mb_substr($e->getMessage(), 0, 300), 'source' => 'taapi'];
        }
    }
}
