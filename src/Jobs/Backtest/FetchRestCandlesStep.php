<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Backtest;

use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Support\Backtest\BinanceRestCandleFetcher;
use Throwable;

/**
 * FetchRestCandlesStep
 *
 * Dispatchable wrapper around BinanceRestCandleFetcher: forward-resumes from the
 * latest DB candle to "now", then scans + fills internal gaps. Binance only.
 * Best-effort (see FetchVisionCandlesStep) — always completes.
 */
final class FetchRestCandlesStep extends BaseQueueableJob
{
    public ExchangeSymbol $exchangeSymbol;

    public string $timeframe;

    public ?int $gapLookbackTs;

    public function __construct(int $exchangeSymbolId, string $timeframe, ?int $gapLookbackTs = null)
    {
        $this->exchangeSymbol = ExchangeSymbol::with('apiSystem')->findOrFail($exchangeSymbolId);
        $this->timeframe = $timeframe;
        $this->gapLookbackTs = $gapLookbackTs;
        $this->retries = 5;
    }

    public function relatable()
    {
        return $this->exchangeSymbol;
    }

    public function compute()
    {
        if (mb_strtolower((string) ($this->exchangeSymbol->apiSystem->canonical ?? '')) !== 'binance') {
            return ['skipped' => true, 'reason' => 'non-Binance symbol — no Binance REST'];
        }

        try {
            $rest = new BinanceRestCandleFetcher;

            return [
                'forward' => $rest->fetch($this->exchangeSymbol, $this->timeframe),
                'gaps' => $rest->fillGaps($this->exchangeSymbol, $this->timeframe, $this->gapLookbackTs),
            ];
        } catch (Throwable $e) {
            return ['error' => mb_substr($e->getMessage(), 0, 300), 'source' => 'rest'];
        }
    }
}
