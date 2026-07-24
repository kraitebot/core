<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Backtest;

use Illuminate\Support\Sleep;
use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Support\Backtest\TaapiCandlesFetcher;
use Kraite\Core\Support\Throttlers\TaapiThrottler;
use StepDispatcher\Models\Step;
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

    public function relatable()
    {
        return $this->exchangeSymbol;
    }

    public function compute(): ?array
    {
        try {
            return (new TaapiCandlesFetcher)->fetch(
                $this->exchangeSymbol,
                $this->timeframe,
                200,
                0,
                fn (): bool => $this->reserveTaapiRequest(),
            );
        } catch (Throwable $e) {
            return ['error' => mb_substr($e->getMessage(), 0, 300), 'source' => 'taapi'];
        }
    }

    private function reserveTaapiRequest(): bool
    {
        $stepId = isset($this->step) && $this->step->exists
            ? $this->step->id
            : null;
        $retryCount = $stepId !== null ? (int) $this->step->retries : 0;

        while (true) {
            $millisecondsToWait = TaapiThrottler::canDispatch($retryCount, null, $stepId);

            if ($millisecondsToWait === 0) {
                TaapiThrottler::recordDispatch(null, $stepId);

                return true;
            }

            if ($stepId !== null) {
                $jitterMaximum = max(50, (int) ($millisecondsToWait * 0.3));
                $this->jobBackoffMs = max(
                    1000,
                    $millisecondsToWait + random_int(0, $jitterMaximum),
                );
                $this->jobBackoffSeconds = 0;

                Step::log($stepId, 'throttled', sprintf(
                    'Throttled by %s::canDispatch | wait=%dms | retry_count=%d',
                    class_basename(TaapiThrottler::class),
                    $this->jobBackoffMs,
                    $retryCount,
                ));

                $this->rescheduleWithoutRetry();

                return false;
            }

            Sleep::for($millisecondsToWait)->milliseconds();
        }
    }
}
