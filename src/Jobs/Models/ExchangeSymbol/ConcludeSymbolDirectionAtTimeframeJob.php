<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Models\ExchangeSymbol;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Contracts\Indicators\DirectionIndicator;
use Kraite\Core\Contracts\Indicators\ValidationIndicator;
use Kraite\Core\Jobs\Atomic\ExchangeSymbol\ConfirmPriceAlignmentWithDirectionJob;
use Kraite\Core\Jobs\Atomic\ExchangeSymbol\CopyDirectionToOtherExchangesJob;
use Kraite\Core\Jobs\Atomic\ExchangeSymbol\QueryAndStoreSupportAndResistanceJob;
use Kraite\Core\Jobs\Models\Indicator\QuerySymbolIndicatorsJob;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Indicator;
use Kraite\Core\Models\IndicatorHistory;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\TradeConfiguration;
use StepDispatcher\Models\Step;

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
        $exchangeSymbol = ExchangeSymbol::with('apiSystem')->findOrFail($this->exchangeSymbolId);

        $allTimeframes = Kraite::timeframes();

        if (empty($allTimeframes)) {
            $response = [
                'result' => 'error',
                'message' => 'No timeframes configured on the kraite singleton row',
            ];
            $this->step->update(['response' => $response]);

            return $response;
        }

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
        $expectedIndicatorCount = Indicator::query()
            ->where('is_active', true)
            ->where('type', 'conclude-indicators')
            ->count();

        if ($latestPerIndicator->count() < $expectedIndicatorCount) {
            // Missing some indicator data - treat as inconclusive
            return $this->handleInconclusiveTimeframe($exchangeSymbol, $allTimeframes);
        }

        // Same-run provenance gate. indicator_histories.timestamp is the
        // wall-clock WRITE time, and one query run upserts every indicator
        // within seconds. If a run only refreshed SOME constructs (a partial
        // TAAPI bulk response — construct-level errors on a 200), MAX() per
        // indicator returns this run's fresh rows alongside a previous run's
        // stale rows (roughly a full timeframe apart). The count check above
        // only proves every indicator has SOME row, not that they came from
        // one run — so without this gate a direction could be concluded from
        // mixed-hour data and stamped current, driving position opening on a
        // signal that never existed at any single point in time. If the
        // spread between the oldest and newest "latest" timestamp exceeds the
        // tolerance, the set is not from one run → inconclusive, retry next
        // cycle. Same-run spread is a few seconds; a cross-run straggler is
        // ~one timeframe behind, so the two never blur.
        $timestamps = $latestPerIndicator
            ->pluck('max_timestamp')
            ->map(static fn ($value): int => (int) $value)
            ->filter(static fn (int $value): bool => $value > 0);

        $maxSpreadSeconds = (int) config('kraite.indicators.max_run_spread_seconds', 300);

        if ($timestamps->isNotEmpty() && ($timestamps->max() - $timestamps->min()) > $maxSpreadSeconds) {
            // Mixed-run indicator set — do not conclude on partial-refresh data.
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
            if (! ($history->indicator)) {
                continue;
            }

            $indicatorData[$history->indicator->canonical] = [
                'result' => $history->data,
            ];
        }

        // Check if we're concluding on the same data we already have
        if ($this->isSameIndicatorData($exchangeSymbol, $indicatorData, $this->timeframe)) {
            // Stamp the attempt time even though no fresh indicator data
            // emerged this run. `indicators_synced_at` is "last time the
            // pipeline ran end-to-end on this symbol" (see exhaustion +
            // path-invalidation branches below for the same rationale).
            // Without this stamp, every long-timeframe symbol whose
            // candle hasn't closed since the last conclude — e.g. a 1d
            // symbol mid-day — ages past the system-health watchdog
            // threshold and floods the operator with false-positive
            // staleness alerts even though the conclude pipeline ran
            // every cron cycle and correctly decided "nothing new".
            $exchangeSymbol->updateSaving([
                'indicators_synced_at' => Carbon::now(),
            ]);

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
            if (is_subclass_of($indicatorClass, DirectionIndicator::class)) {
                // Direction indicator
                if ($conclusion === 'LONG' || $conclusion === 'SHORT') {
                    $directions[] = $conclusion;
                }
            } elseif (is_subclass_of($indicatorClass, ValidationIndicator::class)) {
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

        $this->persistConclusion($exchangeSymbol, $newDirection, $indicatorData);

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
                // Stamp the attempt time even though no direction
                // emerged — `indicators_synced_at` is "last time the
                // pipeline ran end-to-end on this symbol", not "last
                // successful conclusion". Without this stamp, the
                // system-health watchdog flags every uncon-concludable
                // symbol every 5min until the next attempt, flooding
                // the operator with false-positive staleness alerts.
                'indicators_synced_at' => Carbon::now(),
                'has_invalid_indicator_direction' => true,
                'pivot_r3' => null,
                'pivot_r2' => null,
                'pivot_r1' => null,
                'pivot_p' => null,
                'pivot_s1' => null,
                'pivot_s2' => null,
                'pivot_s3' => null,
                'pivot_synced_at' => null,
            ]);

            // Notify admin when direction is invalidated after exhausting all timeframes
            if ($hadDirection) {
                $message = "[ES:{$exchangeSymbol->id}] Symbol {$exchangeSymbol->parsed_trading_pair} direction invalidated (was {$previousDirection}, all timeframes exhausted)";
                $title = 'Direction Invalidated ('.ucfirst($exchangeSymbol->apiSystem->canonical).')';

                // Kraite::notifyAdmins(
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
            // Path invalid - invalidate symbol but keep the attempt stamp
            // (see exhaustion branch above for rationale).
            $exchangeSymbol->updateSaving([
                'direction' => null,
                'indicators_values' => null,
                'indicators_timeframe' => null,
                'indicators_synced_at' => Carbon::now(),
                'has_early_direction_change' => true,
                'pivot_r3' => null,
                'pivot_r2' => null,
                'pivot_r1' => null,
                'pivot_p' => null,
                'pivot_s1' => null,
                'pivot_s2' => null,
                'pivot_s3' => null,
                'pivot_synced_at' => null,
            ]);

            // Notify admin when direction is invalidated due to path inconsistency
            $message = "[ES:{$exchangeSymbol->id}] Symbol {$exchangeSymbol->parsed_trading_pair} direction invalidated (was {$oldDirection}, path inconsistency detected)";
            $title = 'Direction Invalidated ('.ucfirst($exchangeSymbol->apiSystem->canonical).')';

            // Kraite::notifyAdmins(
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

        $this->persistConclusion($exchangeSymbol, $newDirection, $indicatorData);

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
        $group = $this->step->group;

        $this->buildChildChainOnce(function (string $childBlockUuid) use ($conclusions, $group, $nextTimeframe, $shouldCleanup, $symbolId): void {
            Step::create([
                'class' => QuerySymbolIndicatorsJob::class,
                'queue' => 'indicators',
                'block_uuid' => $childBlockUuid,
                'group' => $group,
                'index' => 1,
                'arguments' => [
                    'exchangeSymbolId' => $symbolId,
                    'timeframe' => $nextTimeframe,
                    'previousConclusions' => $conclusions,
                ],
            ]);

            Step::create([
                'class' => self::class,
                'queue' => 'indicators',
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
        });
    }

    /**
     * Persist the symbol conclusion and its follow-up work as one unit.
     */
    private function persistConclusion(ExchangeSymbol $exchangeSymbol, string $direction, array $indicatorData): void
    {
        DB::transaction(function () use ($direction, $exchangeSymbol, $indicatorData): void {
            $lockedStep = Step::query()
                ->whereKey($this->step->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->updateSymbol($exchangeSymbol, $direction, $indicatorData);
            $this->createFinalizationSteps($exchangeSymbol->id, $lockedStep);
        });
    }

    /**
     * Create finalization steps after direction is successfully concluded.
     * These steps confirm price alignment, copy direction to other exchanges,
     * and optionally fetch klines + compute BTC correlation when enabled.
     */
    private function createFinalizationSteps(int $symbolId, Step $lockedStep): void
    {
        $blockUuid = $lockedStep->block_uuid;
        $group = $lockedStep->group;

        $definitions = [
            [
                'class' => ConfirmPriceAlignmentWithDirectionJob::class,
                'index' => 3,
                'arguments' => ['exchangeSymbolId' => $symbolId],
            ],
            [
                'class' => CopyDirectionToOtherExchangesJob::class,
                'index' => 4,
                'arguments' => ['sourceExchangeSymbolId' => $symbolId],
            ],
            [
                'class' => QueryAndStoreSupportAndResistanceJob::class,
                'index' => 4,
                'arguments' => ['exchangeSymbolId' => $symbolId],
            ],
        ];

        if (Kraite::correlationComputationEnabled()) {
            $definitions[] = [
                'class' => CheckKLinesForCorrelationJob::class,
                'index' => 5,
                'arguments' => ['exchangeSymbolId' => $symbolId],
            ];
            $definitions[] = [
                'class' => CalculateBtcCorrelationJob::class,
                'index' => 6,
                'arguments' => ['exchangeSymbolId' => $symbolId],
            ];
            $definitions[] = [
                'class' => CalculateBtcElasticityJob::class,
                'index' => 6,
                'arguments' => ['exchangeSymbolId' => $symbolId],
            ];
        }

        $existingClasses = Step::query()
            ->where('block_uuid', $blockUuid)
            ->whereIn('class', array_column($definitions, 'class'))
            ->pluck('class')
            ->all();

        foreach ($definitions as $definition) {
            if (in_array($definition['class'], $existingClasses, true)) {
                continue;
            }

            Step::create([
                ...$definition,
                'queue' => 'indicators',
                'block_uuid' => $blockUuid,
                'group' => $group,
            ]);
        }
    }

    /**
     * Build readable path string for logging.
     */
    private function buildPathString(array $conclusions, array $allTimeframes): string
    {
        $path = [];
        foreach ($allTimeframes as $tf) {
            if (! (isset($conclusions[$tf]))) {
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
