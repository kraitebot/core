<?php

declare(strict_types=1);

namespace Kraite\Core\_Jobs\Models\ExchangeSymbol;

use Illuminate\Support\Carbon;
use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\_Jobs\Lifecycles\ExchangeSymbols\ConfirmPriceAlignmentWithDirectionJob;
use Kraite\Core\_Jobs\Lifecycles\ExchangeSymbols\CopyDirectionToOtherExchangesJob;
use Kraite\Core\_Jobs\Models\Indicator\QuerySymbolIndicatorsJob;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\IndicatorHistory;
use StepDispatcher\Models\Step;
use Kraite\Core\Models\TradeConfiguration;

use Str;

/**
 * ConcludeSymbolDirectionAtTimeframeJob
 *
 * Concludes trading direction for a single symbol at a single timeframe.
 * Part of atomic per-symbol workflow for progressive indicator analysis.
 *
 * If concluded: Updates symbol and enables trading.
 * If inconclusive: Spawns child workflow for next timeframe.
 * If last timeframe inconclusive: Invalidates symbol.
 */
final class ConcludeSymbolDirectionAtTimeframeJob extends BaseQueueableJob
{
    public int $exchangeSymbolId;

    public string $timeframe;

    public array $previousConclusions;

    public bool $shouldCleanup;

    /**
     * @param  int  $exchangeSymbolId  Symbol to conclude
     * @param  string  $timeframe  Current timeframe being evaluated
     * @param  array  $previousConclusions  Map of previous timeframe conclusions (e.g., ['1h' => 'INCONCLUSIVE'])
     * @param  bool  $shouldCleanup  Whether to clean up indicator histories after completion
     */
    public function __construct(int $exchangeSymbolId, string $timeframe, array $previousConclusions = [], bool $shouldCleanup = true)
    {
        $this->exchangeSymbolId = $exchangeSymbolId;
        $this->timeframe = $timeframe;
        $this->previousConclusions = $previousConclusions;
        $this->shouldCleanup = $shouldCleanup;
        $this->retries = 20;
    }

    public function relatable()
    {
        return ExchangeSymbol::find($this->exchangeSymbolId);
    }

    public function compute()
    {
        $exchangeSymbol = ExchangeSymbol::findOrFail($this->exchangeSymbolId);
        $tradeConfig = TradeConfiguration::getDefault();
        $allTimeframes = $tradeConfig->indicator_timeframes;

        // Query indicator_histories for this symbol + current timeframe
        // Get the latest timestamp for each indicator at this timeframe
        $latestPerIndicator = IndicatorHistory::query()
            ->join('indicators', 'indicator_histories.indicator_id', '=', 'indicators.id')
            ->where('indicator_histories.exchange_symbol_id', $exchangeSymbol->id)
            ->where('indicator_histories.timeframe', $this->timeframe)
            ->where('indicators.type', 'conclude-indicators')
            ->where('indicators.is_active', 1)
            ->selectRaw('indicator_histories.indicator_id, MAX(indicator_histories.timestamp) as max_timestamp')
            ->groupBy('indicator_histories.indicator_id')
            ->get();

        if ($latestPerIndicator->isEmpty()) {
            // No indicator data found - this shouldn't happen if QuerySymbolIndicatorsJob ran properly
            $response = [
                'result' => 'error',
                'message' => "No indicator data found for timeframe {$this->timeframe}",
            ];
            $this->step->update(['response' => $response]);

            return $response;
        }

        // Check if we have data for all expected indicators
        $expectedIndicatorCount = \Kraite\Core\Models\Indicator::query()
            ->where('is_active', true)
            ->where('type', 'conclude-indicators')
            ->count();

        if ($latestPerIndicator->count() < $expectedIndicatorCount) {
            // Missing some indicator data - treat as inconclusive
            return $this->handleInconclusiveTimeframe($exchangeSymbol, $allTimeframes);
        }

        // Now get the actual records at those timestamps
        $histories = collect();
        foreach ($latestPerIndicator as $item) {
            $record = IndicatorHistory::query()
                ->join('indicators', 'indicator_histories.indicator_id', '=', 'indicators.id')
                ->where('indicator_histories.exchange_symbol_id', $exchangeSymbol->id)
                ->where('indicator_histories.timeframe', $this->timeframe)
                ->where('indicator_histories.indicator_id', $item->indicator_id)
                ->where('indicator_histories.timestamp', $item->max_timestamp)
                ->where('indicators.type', 'conclude-indicators')
                ->where('indicators.is_active', 1)
                ->with('indicator')
                ->select('indicator_histories.*')
                ->first();

            if ($record) {
                $histories->push($record);
            }
        }

        // Build indicatorData for later use
        $indicatorData = [];
        foreach ($histories as $history) {
            if (!($history->indicator)) {
                continue;
            }

            $indicatorData[$history->indicator->canonical] = [
                    'result' => $history->data,
                ];
        }

        // Check if we're concluding on the same data we already have
        if ($this->isSameIndicatorData($exchangeSymbol, $indicatorData, $this->timeframe)) {
            $response = [
                'result' => 'skipped',
                'reason' => 'same_indicator_data',
                'message' => "Indicator data unchanged for timeframe {$this->timeframe}",
            ];
            $this->step->update(['response' => $response]);

            return $response;
        }

        // Process indicators to determine conclusion
        $directions = [];
        $validationsPassed = true;

        foreach ($histories as $history) {
            if (! $history->indicator) {
                continue;
            }

            $indicatorClass = $history->indicator->class;
            $conclusion = $history->conclusion;

            // Determine indicator type by checking if the class implements the appropriate interface
            if (is_subclass_of($indicatorClass, \Kraite\Core\Contracts\Indicators\DirectionIndicator::class)) {
                // Direction indicator
                if ($conclusion === 'LONG' || $conclusion === 'SHORT') {
                    $directions[] = $conclusion;
                }
            } elseif (is_subclass_of($indicatorClass, \Kraite\Core\Contracts\Indicators\ValidationIndicator::class)) {
                // Validation indicator
                if ($conclusion === '0' || $conclusion === 0 || $conclusion === false) {
                    // Validation failed - immediately invalidate this timeframe
                    $validationsPassed = false;
                    break;
                }
                // Validation passed (1/true) - continue
            }
        }

        // Check if we have a valid conclusion at this timeframe
        if (! $validationsPassed || count($directions) === 0 || count(array_unique($directions)) !== 1) {
            // INCONCLUSIVE at this timeframe
            return $this->handleInconclusiveTimeframe($exchangeSymbol, $allTimeframes);
        }

        // All indicators agree on a direction
        $newDirection = $directions[0];

        // Build current conclusions including this timeframe
        $currentConclusions = array_merge($this->previousConclusions, [$this->timeframe => $newDirection]);

        // Determine if this is a direction change
        $oldDirection = $exchangeSymbol->direction;

        if (! is_null($oldDirection) && $oldDirection !== $newDirection) {
            // Direction change detected - apply path consistency rule
            return $this->handleDirectionChange(
                $exchangeSymbol,
                $oldDirection,
                $newDirection,
                $currentConclusions,
                $allTimeframes,
                $indicatorData
            );
        }

        // No direction change (first-time or same direction) - update symbol
        $this->updateSymbol($exchangeSymbol, $newDirection, $indicatorData);

        // Create finalization steps (price alignment + copy to other exchanges)
        $this->createFinalizationSteps($exchangeSymbol->id);

        $response = [
            'result' => 'concluded',
            'direction' => $newDirection,
            'timeframe' => $this->timeframe,
            'is_change' => is_null($oldDirection) ? 'first_time' : 'same_direction',
        ];
        $this->step->update(['response' => $response]);

        return $response;
    }

    /**
     * Handle inconclusive timeframe - spawn child workflow for next timeframe or invalidate if last.
     */
    private function handleInconclusiveTimeframe(ExchangeSymbol $exchangeSymbol, array $allTimeframes): array
    {
        // Add current timeframe as INCONCLUSIVE to conclusions
        $currentConclusions = array_merge($this->previousConclusions, [$this->timeframe => 'INCONCLUSIVE']);

        // Find next timeframe
        $currentIndex = array_search($this->timeframe, $allTimeframes);
        if ($currentIndex === false) {
            $response = ['result' => 'error', 'message' => "Invalid timeframe: {$this->timeframe}"];
            $this->step->update(['response' => $response]);

            return $response;
        }

        $nextIndex = $currentIndex + 1;
        if ($nextIndex >= count($allTimeframes)) {
            // No more timeframes - invalidate symbol
            $hadDirection = ! is_null($exchangeSymbol->direction);
            $previousDirection = $exchangeSymbol->direction;

            $exchangeSymbol->updateSaving([
                'direction' => null,
                'indicators_values' => null,
                'indicators_timeframe' => null,
                'indicators_synced_at' => null,
                'has_invalid_indicator_direction' => true,
            ]);

            // Notify admin when direction is invalidated after exhausting all timeframes
            if ($hadDirection) {
                $message = "[ES:{$exchangeSymbol->id}] Symbol {$exchangeSymbol->parsed_trading_pair} direction invalidated (was {$previousDirection}, all timeframes exhausted)";
                $title = 'Direction Invalidated ('.ucfirst($exchangeSymbol->apiSystem->canonical).')';

                // Engine::notifyAdmins(
                //     message: $message,
                //     title: $title,
                //     deliveryGroup: 'indicators'
                // );
            }

            $response = [
                'result' => 'not_concluded',
                'message' => 'All timeframes exhausted without conclusion',
                'path' => $this->buildPathString($currentConclusions, $allTimeframes),
            ];
            $this->step->update(['response' => $response]);

            return $response;
        }

        // Spawn child workflow for next timeframe
        $nextTimeframe = $allTimeframes[$nextIndex];
        $this->spawnNextTimeframeWorkflow($exchangeSymbol->id, $nextTimeframe, $currentConclusions, $this->shouldCleanup);

        $response = [
            'result' => 'inconclusive',
            'next_timeframe' => $nextTimeframe,
            'path' => $this->buildPathString($currentConclusions, $allTimeframes),
        ];
        $this->step->update(['response' => $response]);

        return $response;
    }

    /**
     * Handle direction change with path consistency validation.
     */
    private function handleDirectionChange(
        ExchangeSymbol $exchangeSymbol,
        string $oldDirection,
        string $newDirection,
        array $currentConclusions,
        array $allTimeframes,
        array $indicatorData
    ): array {
        $tradeConfig = TradeConfiguration::getDefault();
        $leastTimeframeIndex = $tradeConfig->least_timeframe_index_to_change_indicator;
        $currentIndex = array_search($this->timeframe, $allTimeframes);

        // Check if we've reached minimum timeframe index for direction changes
        if ($currentIndex < $leastTimeframeIndex) {
            // Too early to change - try next timeframe
            return $this->handleInconclusiveTimeframe($exchangeSymbol, $allTimeframes);
        }

        // Validate path consistency: all previous timeframes must be either NEW direction or INCONCLUSIVE
        $pathValid = true;
        for ($i = 0; $i <= $currentIndex; $i++) {
            $tf = $allTimeframes[$i];
            $tfConclusion = $currentConclusions[$tf] ?? 'INCONCLUSIVE';

            if ($tfConclusion !== $newDirection && $tfConclusion !== 'INCONCLUSIVE') {
                $pathValid = false;
                break;
            }
        }

        if (! $pathValid) {
            // Path invalid - invalidate symbol
            $exchangeSymbol->updateSaving([
                'direction' => null,
                'indicators_values' => null,
                'indicators_timeframe' => null,
                'indicators_synced_at' => null,
                'has_early_direction_change' => true,
            ]);

            // Notify admin when direction is invalidated due to path inconsistency
            $message = "[ES:{$exchangeSymbol->id}] Symbol {$exchangeSymbol->parsed_trading_pair} direction invalidated (was {$oldDirection}, path inconsistency detected)";
            $title = 'Direction Invalidated ('.ucfirst($exchangeSymbol->apiSystem->canonical).')';

            // Engine::notifyAdmins(
            //     message: $message,
            //     title: $title,
            //     deliveryGroup: 'indicators'
            // );

            $response = [
                'result' => 'rejected',
                'reason' => 'path_inconsistency',
                'old_direction' => $oldDirection,
                'new_direction' => $newDirection,
                'path' => $this->buildPathString($currentConclusions, $allTimeframes),
            ];
            $this->step->update(['response' => $response]);

            return $response;
        }

        // Path valid - update symbol with new direction
        $this->updateSymbol($exchangeSymbol, $newDirection, $indicatorData);

        // Create finalization steps (price alignment + copy to other exchanges)
        $this->createFinalizationSteps($exchangeSymbol->id);

        $response = [
            'result' => 'concluded',
            'direction' => $newDirection,
            'timeframe' => $this->timeframe,
            'is_change' => 'direction_changed',
            'old_direction' => $oldDirection,
        ];
        $this->step->update(['response' => $response]);

        return $response;
    }

    /**
     * Update exchange symbol with concluded direction.
     */
    private function updateSymbol(ExchangeSymbol $exchangeSymbol, string $direction, array $indicatorData): void
    {
        $exchangeSymbol->updateSaving([
            'direction' => $direction,
            'indicators_timeframe' => $this->timeframe,
            'indicators_values' => $this->normalizeScientificNotation($indicatorData),
            'indicators_synced_at' => Carbon::now(),
            'has_no_indicator_data' => false,
            'has_price_trend_misalignment' => false,
            'has_early_direction_change' => false,
            'has_invalid_indicator_direction' => false,
        ]);
    }

    /**
     * Recursively convert scientific notation floats to decimal strings.
     * Prevents JSON from storing numbers like 1.849344254358247e-8 instead of 0.00000001849344254358247
     */
    private function normalizeScientificNotation(mixed $data): mixed
    {
        if (is_array($data)) {
            return array_map(callback: function ($value) {
                return $this->normalizeScientificNotation($value);
            }, array: $data);
        }

        if (is_float($data)) {
            return sprintf('%.20f', $data);
        }

        return $data;
    }

    /**
     * Spawn child workflow for next timeframe.
     * Only creates Query and Conclude steps - finalization steps are created
     * dynamically by createFinalizationSteps() when direction is concluded.
     */
    private function spawnNextTimeframeWorkflow(int $symbolId, string $nextTimeframe, array $conclusions, bool $shouldCleanup): void
    {
        $childBlockUuid = Str::uuid()->toString();
        $group = $this->step->group;

        // INDEX 1: Query indicator data
        Step::create([
            'class' => QuerySymbolIndicatorsJob::class,
            'queue' => 'default',
            'block_uuid' => $childBlockUuid,
            'group' => $group,
            'index' => 1,
            'arguments' => [
                'exchangeSymbolId' => $symbolId,
                'timeframe' => $nextTimeframe,
                'previousConclusions' => $conclusions,
            ],
        ]);

        // INDEX 2: Conclude direction
        Step::create([
            'class' => self::class,
            'queue' => 'default',
            'block_uuid' => $childBlockUuid,
            'group' => $group,
            'index' => 2,
            'arguments' => [
                'exchangeSymbolId' => $symbolId,
                'timeframe' => $nextTimeframe,
                'previousConclusions' => $conclusions,
                'shouldCleanup' => $shouldCleanup,
            ],
        ]);

        // Link child workflow to current step
        $this->step->update(['child_block_uuid' => $childBlockUuid]);
    }

    /**
     * Create finalization steps after direction is successfully concluded.
     * These steps confirm price alignment and copy direction to other exchanges.
     */
    private function createFinalizationSteps(int $symbolId): void
    {
        $blockUuid = $this->step->block_uuid;
        $group = $this->step->group;

        // INDEX 3: Confirm price alignment
        Step::create([
            'class' => ConfirmPriceAlignmentWithDirectionJob::class,
            'queue' => 'default',
            'block_uuid' => $blockUuid,
            'group' => $group,
            'index' => 3,
            'arguments' => [
                'exchangeSymbolId' => $symbolId,
            ],
        ]);

        // INDEX 4: Copy direction to other exchanges
        Step::create([
            'class' => CopyDirectionToOtherExchangesJob::class,
            'queue' => 'default',
            'block_uuid' => $blockUuid,
            'group' => $group,
            'index' => 4,
            'arguments' => [
                'sourceExchangeSymbolId' => $symbolId,
            ],
        ]);
    }

    /**
     * Build readable path string for logging.
     */
    private function buildPathString(array $conclusions, array $allTimeframes): string
    {
        $path = [];
        foreach ($allTimeframes as $tf) {
            if (!(isset($conclusions[$tf]))) {
                continue;
            }

            $path[] = "{$tf}={$conclusions[$tf]}";
        }

        return implode(separator: ' -> ', array: $path);
    }

    /**
     * Check if the new indicator data is the same as what's already stored on the exchange symbol.
     * Compares candle timestamps from candle-comparison indicator to determine if data has changed.
     */
    private function isSameIndicatorData(ExchangeSymbol $exchangeSymbol, array $newIndicatorData, string $timeframe): bool
    {
        // If symbol has no existing indicators_values, data is new
        if (! $exchangeSymbol->indicators_values || ! $exchangeSymbol->indicators_timeframe) {
            return false;
        }

        // If the stored timeframe is different, data is new
        if ($exchangeSymbol->indicators_timeframe !== $timeframe) {
            return false;
        }

        // Extract candle timestamps from stored data
        $storedData = $exchangeSymbol->indicators_values;
        $storedCandleData = $storedData['candle-comparison']['result'] ?? null;
        $storedTimestamps = $storedCandleData['timestamp'] ?? null;

        // Extract candle timestamps from new data
        $newCandleData = $newIndicatorData['candle-comparison']['result'] ?? null;
        $newTimestamps = $newCandleData['timestamp'] ?? null;

        // If we can't find timestamps in either dataset, consider them different (to be safe)
        if (! $storedTimestamps || ! $newTimestamps) {
            return false;
        }

        // Compare the timestamp arrays
        // We compare the last timestamp as it represents the most recent candle
        $storedLastTimestamp = is_array($storedTimestamps) ? end($storedTimestamps) : $storedTimestamps;
        $newLastTimestamp = is_array($newTimestamps) ? end($newTimestamps) : $newTimestamps;

        return $storedLastTimestamp === $newLastTimestamp;
    }
}
