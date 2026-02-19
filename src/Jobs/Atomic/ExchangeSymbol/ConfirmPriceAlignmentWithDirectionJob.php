<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\ExchangeSymbol;

use Exception;
use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Jobs\Models\ExchangeSymbol\ConcludeSymbolDirectionAtTimeframeJob;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Indicator;
use Kraite\Core\Models\IndicatorHistory;
use StepDispatcher\Models\Step;
use Throwable;

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
        $indicator = Indicator::firstWhere('canonical', 'candle-comparison');

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
                'indicators_synced_at' => null,
                'has_no_indicator_data' => true,
            ]);

            return ['response' => "Price alignment for {$this->exchangeSymbol->parsed_trading_pair} REMOVED due to missing indicator history"];
        }

        // Extract data from stored JSON
        $data = $history->data;

        // Compare current candle's open vs close to determine if price movement aligns with direction
        // This is more reliable than comparing previous close vs current close because:
        // - The current candle's open is fixed (doesn't change)
        // - The current candle's close represents the actual price movement within this timeframe
        $currentOpen = (float) $data['open'][1];
        $currentClose = (float) $data['close'][1];
        $direction = $this->exchangeSymbol->direction;
        $timeframe = $this->exchangeSymbol->indicators_timeframe;

        // LONG requires price to be rising (close > open)
        // SHORT requires price to be falling (close < open)
        if (($direction === 'LONG' && $currentClose <= $currentOpen) ||
            ($direction === 'SHORT' && $currentClose >= $currentOpen)
        ) {
            $this->exchangeSymbol->updateSaving([
                'direction' => null,
                'indicators_values' => null,
                'indicators_timeframe' => null,
                'indicators_synced_at' => null,
                'has_price_trend_misalignment' => true,
            ]);

            return ['response' => "Price alignment for {$this->exchangeSymbol->parsed_trading_pair}-{$direction} REMOVED due to price misalignment (Open: {$currentOpen}, Close: {$currentClose}, timeframe: {$timeframe})"];
        }

        // Last step: activate exchange symbol for trading (clear all validation flags).
        $this->exchangeSymbol->updateSaving([
            'has_no_indicator_data' => false,
            'has_price_trend_misalignment' => false,
        ]);

        // Send notification based on direction change status from previous step
        $this->sendDirectionNotification($direction, $timeframe);

        return ['response' => "Price alignment for {$this->exchangeSymbol->parsed_trading_pair}-{$direction} CONFIRMED (Open: {$currentOpen}, Close: {$currentClose}, timeframe: {$timeframe})"];
    }

    public function resolveException(Throwable $e): void
    {
        // Engine::notifyAdmins(
        //     message: "[{$this->exchangeSymbol->id}] - ExchangeSymbol price alignment error - ".ExceptionParser::with($e)->friendlyMessage(),
        //     title: "[S:{$this->step->id} ES:{$this->exchangeSymbol->id}] ".class_basename(self::class).' - Error',
        //     deliveryGroup: 'exceptions'
        // );
    }

    /**
     * Send appropriate notification based on direction change status from previous step.
     */
    private function sendDirectionNotification(string $direction, string $timeframe): void
    {
        // Guard clause: step might not be initialized in tests
        if (! isset($this->step)) {
            return;
        }

        // Find the previous step (ConcludeSymbolDirectionAtTimeframeJob) in the same block
        $previousStep = Step::query()
            ->where('block_uuid', $this->step->block_uuid)
            ->where('class', ConcludeSymbolDirectionAtTimeframeJob::class)
            ->first();

        if (! $previousStep || ! $previousStep->response) {
            return;
        }

        $response = $previousStep->response;
        $isChange = $response['is_change'] ?? 'unknown';
        $exchangeName = ucfirst($this->exchangeSymbol->apiSystem->canonical);

        // Send notification based on change status
        if ($isChange === 'first_time') {
            $message = "[ES:{$this->exchangeSymbol->id}] Symbol {$this->exchangeSymbol->parsed_trading_pair} now has direction: {$direction} (timeframe: {$timeframe})";
            $title = "Direction Set ({$exchangeName})";

            // Engine::notifyAdmins(
            //     message: $message,
            //     title: $title,
            //     deliveryGroup: 'indicators'
            // );
        } elseif ($isChange === 'direction_changed') {
            $oldDirection = $response['old_direction'] ?? 'unknown';
            $message = "[ES:{$this->exchangeSymbol->id}] Symbol {$this->exchangeSymbol->parsed_trading_pair} direction changed: {$oldDirection} → {$direction} (timeframe: {$timeframe})";
            $title = "Direction Changed ({$exchangeName})";

            // Engine::notifyAdmins(
            //     message: $message,
            //     title: $title,
            //     deliveryGroup: 'indicators'
            // );
        }
        // No notification for 'same_direction' case
    }
}
