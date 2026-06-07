<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Models\ExchangeSymbol;

use Illuminate\Support\Facades\Cache;
use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Models\Candle;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\Symbol;

/**
 * CalculateBtcCorrelationJob
 *
 * Calculates correlation between a token and BTC using historical candle data.
 * Correlation is calculated for all timeframes configured in the exchange's ApiSystem.
 *
 * Three correlation types are calculated per timeframe:
 * - Pearson: Linear relationship between price movements
 * - Spearman: Rank-based correlation (more robust for crypto volatility)
 * - Rolling: Correlation over recent window (configurable via method)
 *
 * Results are stored as JSON arrays indexed by timeframe in exchange_symbols columns.
 */
final class CalculateBtcCorrelationJob extends BaseQueueableJob
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

        // Skip if correlation is disabled (singleton flag wins, else config)
        if (! Kraite::correlationComputationEnabled()) {
            return ['skipped' => true, 'reason' => 'Correlation calculation disabled in config'];
        }

        $exchangeSymbol = ExchangeSymbol::with('apiSystem')->findOrFail($this->exchangeSymbolId);

        // Find BTC symbol dynamically
        $btcSymbol = Symbol::where('token', $config['btc_token'])->first();

        if (! $btcSymbol) {
            return ['skipped' => true, 'reason' => "BTC symbol not found (token={$config['btc_token']})"];
        }

        // Skip if this IS BTC (can't correlate with itself)
        if ($exchangeSymbol->symbol_id === $btcSymbol->id) {
            return ['skipped' => true, 'reason' => 'Cannot correlate BTC with itself'];
        }

        // Find BTC exchange_symbol for same exchange
        $btcExchangeSymbol = ExchangeSymbol::query()
            ->where('symbol_id', $btcSymbol->id)
            ->where('api_system_id', $exchangeSymbol->api_system_id)
            ->where('quote', $exchangeSymbol->quote)
            ->first();

        if (! $btcExchangeSymbol) {
            return ['skipped' => true, 'reason' => 'BTC not found on same exchange'];
        }

        $timeframes = Kraite::timeframes();
        if (empty($timeframes)) {
            return ['skipped' => true, 'reason' => 'No timeframes configured on the kraite singleton'];
        }

        // Calculate correlation for each timeframe
        $pearsonResults = [];
        $spearmanResults = [];
        $rollingResults = [];
        $stabilityResults = [];
        $timeframeDetails = [];

        foreach ($timeframes as $timeframe) {
            $result = $this->calculateCorrelationForTimeframe(
                $exchangeSymbol,
                $btcExchangeSymbol,
                $timeframe,
                $config
            );

            if (isset($result['error'])) {
                // Timeframe had insufficient data, skip but don't fail entire job
                $timeframeDetails[$timeframe] = $result;

                continue;
            }

            // Store results indexed by timeframe
            $pearsonResults[$timeframe] = $result['pearson'];
            $spearmanResults[$timeframe] = $result['spearman'];
            $rollingResults[$timeframe] = $result['rolling'];

            if ($result['stability'] !== null) {
                $stabilityResults[$timeframe] = $result['stability'];
            }

            $timeframeDetails[$timeframe] = [
                'candles_analyzed' => $result['candles_analyzed'],
                'pearson' => round($result['pearson'], precision: 4),
                'spearman' => round($result['spearman'], precision: 4),
                'rolling' => round($result['rolling'], precision: 4),
                'stability' => $result['stability'] !== null
                    ? round($result['stability'], precision: 4)
                    : null,
            ];
        }

        // Only save if we calculated at least one timeframe
        if (! empty($pearsonResults)) {
            $exchangeSymbol->btc_correlation_pearson = $pearsonResults;
            $exchangeSymbol->btc_correlation_spearman = $spearmanResults;
            $exchangeSymbol->btc_correlation_rolling = $rollingResults;
            $exchangeSymbol->btc_correlation_stability = $stabilityResults !== []
                ? $stabilityResults
                : null;
            $exchangeSymbol->save();
        }

        return [
            'exchange_symbol_id' => $exchangeSymbol->id,
            'symbol' => $exchangeSymbol->token,
            'timeframes_calculated' => count($pearsonResults),
            'timeframes' => $timeframeDetails,
        ];
    }

    /**
     * Calculate correlation for a single timeframe
     */
    public function calculateCorrelationForTimeframe(
        ExchangeSymbol $exchangeSymbol,
        ExchangeSymbol $btcExchangeSymbol,
        string $timeframe,
        array $config
    ): array {
        // Fetch candles for this token, keyed by timestamp for O(1) pairing below.
        $tokenCloses = $this->fetchCandleCloses(
            $exchangeSymbol->id,
            $timeframe,
            (int) $config['window_size']
        );

        // BTC candles are identical across every correlation job running in the
        // same tick (hundreds per minute hammering the same rows). Cache per
        // (exchange_symbol, timeframe, window) for a short TTL so one query
        // serves the whole batch.
        $btcCloses = Cache::remember(
            "btc_candle_closes:{$btcExchangeSymbol->id}:{$timeframe}:{$config['window_size']}",
            30,
            fn (): array => $this->fetchCandleCloses(
                $btcExchangeSymbol->id,
                $timeframe,
                (int) $config['window_size']
            )
        );

        // Overlap by timestamp — both maps are already keyed, intersection is O(n).
        $commonTimestamps = array_intersect_key($tokenCloses, $btcCloses);

        if (empty($commonTimestamps)) {
            return [
                'error' => 'No overlapping candle timestamps found',
                'token_candles' => count($tokenCloses),
                'btc_candles' => count($btcCloses),
            ];
        }

        $tokenPrices = [];
        $btcPrices = [];

        foreach ($commonTimestamps as $timestamp => $_) {
            $tokenPrices[] = $tokenCloses[$timestamp];
            $btcPrices[] = $btcCloses[$timestamp];
        }

        // Need at least 2 aligned candles for correlation
        if (count($tokenPrices) < 2) {
            return [
                'error' => 'Need at least 2 aligned candles for correlation',
                'aligned_candles' => count($tokenPrices),
            ];
        }

        // Calculate correlations
        $pearson = $this->calculatePearsonCorrelation($tokenPrices, $btcPrices);
        $spearman = $this->calculateSpearmanCorrelation($tokenPrices, $btcPrices);
        $rolling = $this->calculateRollingCorrelation(
            $tokenPrices,
            $btcPrices,
            $config['rolling']['window_size'],
            $config['rolling']['method'],
            $config['rolling']['step_size']
        );

        // Stability is the std-dev of the per-window rolling
        // correlation series — independent of the rolling-method
        // (recent / average / weighted) used for the headline rolling
        // value. Always uses the full sliding-window scan so we get a
        // meaningful dispersion measure even when the headline method
        // is `recent`.
        $stability = $this->calculateRollingStability(
            $tokenPrices,
            $btcPrices,
            (int) $config['rolling']['window_size'],
            (int) $config['rolling']['step_size']
        );

        return [
            'pearson' => $pearson,
            'spearman' => $spearman,
            'rolling' => $rolling,
            'stability' => $stability,
            'candles_analyzed' => count($tokenPrices),
        ];
    }

    /**
     * Calculate Pearson correlation coefficient
     * Measures linear relationship between two datasets
     */
    public function calculatePearsonCorrelation(array $x, array $y): float
    {
        $n = count($x);

        if ($n < 2) {
            return 0.0;
        }

        $meanX = array_sum($x) / $n;
        $meanY = array_sum($y) / $n;

        $numerator = 0;
        $denomX = 0;
        $denomY = 0;

        for ($i = 0; $i < $n; $i++) {
            $diffX = $x[$i] - $meanX;
            $diffY = $y[$i] - $meanY;

            $numerator += $diffX * $diffY;
            $denomX += $diffX * $diffX;
            $denomY += $diffY * $diffY;
        }

        if ($denomX === 0.0 || $denomY === 0.0) {
            return 0.0;
        }

        return $numerator / sqrt($denomX * $denomY);
    }

    /**
     * Calculate Spearman rank correlation
     * More robust to outliers than Pearson
     */
    public function calculateSpearmanCorrelation(array $x, array $y): float
    {
        // Convert values to ranks
        $ranksX = $this->rankArray($x);
        $ranksY = $this->rankArray($y);

        // Apply Pearson to ranks
        return $this->calculatePearsonCorrelation($ranksX, $ranksY);
    }

    /**
     * Standard deviation of the per-window rolling correlation
     * series. Captures how jittery the correlation is across the
     * lookback. Returns null when there are fewer than two complete
     * sub-windows — a single window has no dispersion to measure.
     */
    public function calculateRollingStability(
        array $x,
        array $y,
        int $windowSize,
        int $stepSize,
    ): ?float {
        $n = count($x);

        if ($n < $windowSize + $stepSize) {
            return null;
        }

        $correlations = [];

        for ($i = 0; $i <= $n - $windowSize; $i += max(1, $stepSize)) {
            $windowX = array_slice($x, $i, length: $windowSize);
            $windowY = array_slice($y, $i, length: $windowSize);

            $correlations[] = $this->calculatePearsonCorrelation($windowX, $windowY);
        }

        if (count($correlations) < 2) {
            return null;
        }

        $mean = array_sum($correlations) / count($correlations);
        $varianceSum = 0.0;

        foreach ($correlations as $correlation) {
            $varianceSum += ($correlation - $mean) ** 2;
        }

        return sqrt($varianceSum / count($correlations));
    }

    /**
     * Calculate rolling correlation
     * Supports three methods: recent, average, weighted
     */
    public function calculateRollingCorrelation(
        array $x,
        array $y,
        int $windowSize,
        string $method,
        int $stepSize
    ): float {
        $n = count($x);

        if ($n < $windowSize) {
            // Not enough data for rolling window, return full correlation
            return $this->calculatePearsonCorrelation($x, $y);
        }

        if ($method === 'recent') {
            // Return correlation of most recent window only
            $recentX = array_slice($x, -$windowSize);
            $recentY = array_slice($y, -$windowSize);

            return $this->calculatePearsonCorrelation($recentX, $recentY);
        }

        // Calculate sliding window correlations
        $correlations = [];
        $weights = [];

        for ($i = 0; $i <= $n - $windowSize; $i += $stepSize) {
            $windowX = array_slice($x, $i, length: $windowSize);
            $windowY = array_slice($y, $i, length: $windowSize);

            $correlation = $this->calculatePearsonCorrelation($windowX, $windowY);
            $correlations[] = $correlation;

            // Weight: more recent windows get higher weight (exponential decay)
            if ($method === 'weighted') {
                $position = $i / max(1, ($n - $windowSize));
                $weights[] = exp($position); // Exponential weight favoring recent data
            } else {
                $weights[] = 1.0; // Equal weight for 'average' method
            }
        }

        if (empty($correlations)) {
            return 0.0;
        }

        // Calculate weighted average
        $totalWeight = array_sum($weights);
        $weightedSum = 0;

        foreach ($correlations as $idx => $corr) {
            $weightedSum += $corr * $weights[$idx];
        }

        return $weightedSum / $totalWeight;
    }

    /**
     * Convert array values to ranks (for Spearman)
     * Handles ties by assigning average rank
     */
    public function rankArray(array $data): array
    {
        $sorted = $data;
        asort($sorted);

        $ranks = [];
        $rank = 1;

        foreach ($sorted as $key => $value) {
            $ranks[$key] = $rank;
            $rank++;
        }

        // Return ranks in original order
        $orderedRanks = [];
        foreach ($data as $key => $_) {
            $orderedRanks[] = $ranks[$key];
        }

        return $orderedRanks;
    }

    /**
     * Fetch close prices for a symbol/timeframe window, keyed by timestamp and
     * sorted chronologically. Returns [timestamp => close] so callers can pair
     * series in O(1) via array access instead of Collection::firstWhere scans.
     *
     * @return array<int|string, float>
     */
    private function fetchCandleCloses(int $exchangeSymbolId, string $timeframe, int $windowSize): array
    {
        $rows = Candle::query()
            ->where('exchange_symbol_id', $exchangeSymbolId)
            ->where('timeframe', $timeframe)
            ->orderBy('timestamp', 'desc')
            ->limit($windowSize)
            ->get(['timestamp', 'close']);

        $closes = [];
        foreach ($rows as $row) {
            $closes[$row->timestamp] = (float) $row->close;
        }

        // Restore chronological order so downstream sliding-window logic
        // treats the array as oldest → newest.
        ksort($closes);

        return $closes;
    }
}
