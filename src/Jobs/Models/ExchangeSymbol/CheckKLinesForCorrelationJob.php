<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Models\ExchangeSymbol;

use Illuminate\Support\Str;
use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Models\Candle;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\TokenMapper;
use StepDispatcher\Models\Step;

/**
 * CheckKLinesForCorrelationJob
 *
 * Checks if the candles table has enough data for BTC correlation calculation.
 * If candles are missing for BTC or the symbol at the concluded timeframe,
 * spawns a child block with FetchKlinesJob steps to fetch them.
 *
 * If candles already exist (e.g., from cron-fetch-klines), does nothing
 * and the pipeline proceeds to CalculateBtcCorrelationJob.
 */
final class CheckKLinesForCorrelationJob extends BaseQueueableJob
{
    public int $exchangeSymbolId;

    public function __construct(int $exchangeSymbolId)
    {
        $this->exchangeSymbolId = $exchangeSymbolId;
    }

    public function relatable()
    {
        return ExchangeSymbol::find($this->exchangeSymbolId);
    }

    public function compute()
    {
        $config = config('kraite.correlation');

        if (! Kraite::correlationComputationEnabled()) {
            return ['skipped' => true, 'reason' => 'Correlation disabled'];
        }

        $exchangeSymbol = ExchangeSymbol::with('apiSystem')->findOrFail($this->exchangeSymbolId);

        if (! $exchangeSymbol->indicators_timeframe) {
            return ['skipped' => true, 'reason' => 'No concluded timeframe'];
        }

        $timeframe = $exchangeSymbol->indicators_timeframe;
        $windowSize = (int) ($config['window_size'] ?? 500);

        // Find BTC exchange symbol on the same exchange + quote
        $btcExchangeSymbol = $this->findBtcExchangeSymbol($exchangeSymbol);

        if (! $btcExchangeSymbol) {
            return ['skipped' => true, 'reason' => 'BTC symbol not found on same exchange/quote'];
        }

        // Skip if this IS BTC
        if ($exchangeSymbol->id === $btcExchangeSymbol->id) {
            return ['skipped' => true, 'reason' => 'Symbol is BTC itself'];
        }

        // Check candle counts
        $btcCandleCount = Candle::query()
            ->where('exchange_symbol_id', $btcExchangeSymbol->id)
            ->where('timeframe', $timeframe)
            ->count();

        $symbolCandleCount = Candle::query()
            ->where('exchange_symbol_id', $exchangeSymbol->id)
            ->where('timeframe', $timeframe)
            ->count();

        $missingSteps = [];

        if ($btcCandleCount < $windowSize) {
            $missingSteps[] = [
                'exchange_symbol_id' => $btcExchangeSymbol->id,
                'label' => "BTC/{$exchangeSymbol->quote} ({$exchangeSymbol->apiSystem->canonical})",
                'existing' => $btcCandleCount,
            ];
        }

        if ($symbolCandleCount < $windowSize) {
            $missingSteps[] = [
                'exchange_symbol_id' => $exchangeSymbol->id,
                'label' => "{$exchangeSymbol->token}/{$exchangeSymbol->quote} ({$exchangeSymbol->apiSystem->canonical})",
                'existing' => $symbolCandleCount,
            ];
        }

        if (empty($missingSteps)) {
            return [
                'result' => 'candles_present',
                'btc_candles' => $btcCandleCount,
                'symbol_candles' => $symbolCandleCount,
                'required' => $windowSize,
            ];
        }

        // Create child block with FetchKlines steps
        $childBlockUuid = Str::uuid()->toString();
        $group = $this->step->group;

        foreach ($missingSteps as $missing) {
            Step::create([
                'class' => FetchKlinesJob::class,
                'queue' => 'indicators',
                'block_uuid' => $childBlockUuid,
                'group' => $group,
                'index' => 1,
                'arguments' => [
                    'exchangeSymbolId' => $missing['exchange_symbol_id'],
                    'timeframe' => $timeframe,
                    'limit' => $windowSize,
                ],
            ]);
        }

        // Link child block to this step
        $this->step->update(['child_block_uuid' => $childBlockUuid]);

        return [
            'result' => 'fetching_candles',
            'timeframe' => $timeframe,
            'window_size' => $windowSize,
            'child_block_uuid' => $childBlockUuid,
            'steps_created' => count($missingSteps),
            'details' => array_map(fn ($m) => "{$m['label']}: {$m['existing']}/{$windowSize}", $missingSteps),
        ];
    }

    /**
     * Find the BTC exchange symbol on the same exchange and quote.
     * Uses TokenMapper to handle exchange-specific token names (e.g., XBT on KuCoin).
     */
    private function findBtcExchangeSymbol(ExchangeSymbol $exchangeSymbol): ?ExchangeSymbol
    {
        $btcToken = (string) config('kraite.correlation.btc_token', 'BTC');

        // Check if this exchange uses a different token name for BTC
        $tokenMapper = TokenMapper::query()
            ->where('binance_token', $btcToken)
            ->where('other_api_system_id', $exchangeSymbol->api_system_id)
            ->first();

        $resolvedToken = $tokenMapper->other_token ?? $btcToken;

        return ExchangeSymbol::query()
            ->where('api_system_id', $exchangeSymbol->api_system_id)
            ->where('token', $resolvedToken)
            ->where('quote', $exchangeSymbol->quote)
            ->first();
    }
}
