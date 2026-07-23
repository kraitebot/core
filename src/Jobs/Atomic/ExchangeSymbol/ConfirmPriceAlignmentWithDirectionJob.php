<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\ExchangeSymbol;

use Exception;
use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Indicator;
use Kraite\Core\Models\IndicatorHistory;
use Kraite\Core\Support\Math;

final class ConfirmPriceAlignmentWithDirectionJob extends BaseQueueableJob
{
    public ?ExchangeSymbol $exchangeSymbol = null;

    public function __construct(int $exchangeSymbolId)
    {
        $this->exchangeSymbol = ExchangeSymbol::with(['symbol', 'apiSystem'])->findOrFail($exchangeSymbolId);
    }

    public function relatable(): ?ExchangeSymbol
    {
        return $this->exchangeSymbol;
    }

    /**
     * @return array<string, mixed>
     */
    public function compute(): array
    {
        // Skip if no direction was concluded - nothing to confirm
        if (! $this->exchangeSymbol->direction) {
            return ['response' => "Skipped - no direction set for {$this->exchangeSymbol->parsed_trading_pair}"];
        }

        // Get the candle-comparison indicator
        $indicator = Indicator::canonical('candle-comparison')->first();

        if (! $indicator) {
            throw new Exception('Indicator "candle-comparison" not found');
        }

        // Fetch the most recent indicator history for this symbol
        $history = IndicatorHistory::query()
            ->where('exchange_symbol_id', $this->exchangeSymbol->id)
            ->where('indicator_id', $indicator->id)
            ->where('timeframe', $this->exchangeSymbol->indicators_timeframe)
            ->latest('timestamp')
            ->first();

        if (! $history) {
            $this->exchangeSymbol->updateSaving([
                'direction' => null,
                'indicators_values' => null,
                'indicators_timeframe' => null,
                // "Last attempt" stamp survives invalidation — see
                // ConcludeSymbolDirectionAtTimeframeJob for rationale.
                // Avoids spam from the system-health watchdog when the
                // symbol legitimately can't be concluded right now.
                'indicators_synced_at' => now(),
                'has_no_indicator_data' => true,
                'pivot_r3' => null,
                'pivot_r2' => null,
                'pivot_r1' => null,
                'pivot_p' => null,
                'pivot_s1' => null,
                'pivot_s2' => null,
                'pivot_s3' => null,
                'pivot_synced_at' => null,
            ]);

            return ['response' => "Price alignment for {$this->exchangeSymbol->parsed_trading_pair} REMOVED due to missing indicator history"];
        }

        // Extract data from stored JSON
        $data = $history->data;

        // Compare current candle's open vs close to determine if price movement aligns with direction
        // This is more reliable than comparing previous close vs current close because:
        // - The current candle's open is fixed (doesn't change)
        // - The current candle's close represents the actual price movement within this timeframe
        $currentOpen = (string) $data['open'][1];
        $currentClose = (string) $data['close'][1];
        $direction = $this->exchangeSymbol->direction;
        $timeframe = $this->exchangeSymbol->indicators_timeframe;

        // LONG requires price to be rising (close > open)
        // SHORT requires price to be falling (close < open)
        if (($direction === 'LONG' && Math::lte($currentClose, $currentOpen)) ||
            ($direction === 'SHORT' && Math::gte($currentClose, $currentOpen))
        ) {
            $this->exchangeSymbol->updateSaving([
                'direction' => null,
                'indicators_values' => null,
                'indicators_timeframe' => null,
                'indicators_synced_at' => now(),
                'has_price_trend_misalignment' => true,
                'pivot_r3' => null,
                'pivot_r2' => null,
                'pivot_r1' => null,
                'pivot_p' => null,
                'pivot_s1' => null,
                'pivot_s2' => null,
                'pivot_s3' => null,
                'pivot_synced_at' => null,
            ]);

            return ['response' => "Price alignment for {$this->exchangeSymbol->parsed_trading_pair}-{$direction} REMOVED due to price misalignment (Open: {$currentOpen}, Close: {$currentClose}, timeframe: {$timeframe})"];
        }

        // Last step: activate exchange symbol for trading (clear all validation flags).
        $this->exchangeSymbol->updateSaving([
            'has_no_indicator_data' => false,
            'has_price_trend_misalignment' => false,
        ]);

        return ['response' => "Price alignment for {$this->exchangeSymbol->parsed_trading_pair}-{$direction} CONFIRMED (Open: {$currentOpen}, Close: {$currentClose}, timeframe: {$timeframe})"];
    }
}
