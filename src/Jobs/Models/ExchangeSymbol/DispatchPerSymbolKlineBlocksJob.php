<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Models\ExchangeSymbol;

use Illuminate\Support\Str;
use Kraite\Core\Abstracts\BaseQueueableJob;
use StepDispatcher\Models\Step;

/**
 * DispatchPerSymbolKlineBlocksJob
 *
 * Orchestrator that fires one independent block per exchange symbol after the
 * shared BTC baseline klines have been fetched. Each per-symbol block contains:
 *
 *   index 1: FetchKlinesJob — one per timeframe for this symbol
 *   index 2: CalculateBtcCorrelationJob + CalculateBtcElasticityJob
 *
 * Correlation + elasticity for a symbol therefore fire as soon as that symbol's
 * own klines complete, independently of any other symbol. The BTC dependency is
 * satisfied upstream: this job only dispatches after the shared block's index-1
 * BTC klines are concluded, so the DB candles table is already populated by the
 * time any correlation job reads from it.
 */
final class DispatchPerSymbolKlineBlocksJob extends BaseQueueableJob
{
    /** @var array<int, int> */
    public array $exchangeSymbolIds;

    /** @var array<int, string> */
    public array $timeframes;

    public int $limit;

    /**
     * @param  array<int, int>  $exchangeSymbolIds  Symbols to spin up blocks for (excludes BTC baselines)
     * @param  array<int, string>  $timeframes  Timeframes to fetch per symbol
     * @param  int  $limit  Kline count per timeframe
     */
    public function __construct(array $exchangeSymbolIds, array $timeframes, int $limit)
    {
        $this->exchangeSymbolIds = $exchangeSymbolIds;
        $this->timeframes = $timeframes;
        $this->limit = $limit;
    }

    public function compute(): array
    {
        $blocksCreated = 0;

        foreach ($this->exchangeSymbolIds as $exchangeSymbolId) {
            $blockUuid = Str::uuid()->toString();

            foreach ($this->timeframes as $timeframe) {
                Step::create([
                    'block_uuid' => $blockUuid,
                    'index' => 1,
                    'class' => FetchKlinesJob::class,
                    'queue' => 'indicators',
                    'arguments' => [
                        'exchangeSymbolId' => $exchangeSymbolId,
                        'timeframe' => $timeframe,
                        'limit' => $this->limit,
                    ],
                ]);
            }

            Step::create([
                'block_uuid' => $blockUuid,
                'index' => 2,
                'class' => CalculateBtcCorrelationJob::class,
                'queue' => 'indicators',
                'arguments' => ['exchangeSymbolId' => $exchangeSymbolId],
            ]);

            Step::create([
                'block_uuid' => $blockUuid,
                'index' => 2,
                'class' => CalculateBtcElasticityJob::class,
                'queue' => 'indicators',
                'arguments' => ['exchangeSymbolId' => $exchangeSymbolId],
            ]);

            $blocksCreated++;
        }

        return [
            'symbols_dispatched' => $blocksCreated,
            'timeframes' => $this->timeframes,
            'limit' => $this->limit,
            'message' => "Spun up {$blocksCreated} per-symbol kline/correlation blocks",
        ];
    }
}
