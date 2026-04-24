<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Models\ExchangeSymbol;

use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Models\Candle;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\Symbol;

/**
 * CalculateBtcElasticityJob
 *
 * Calculates price elasticity between a token and BTC using historical candle data.
 * Elasticity is calculated for all timeframes configured in the exchange's ApiSystem.
 *
 * Elasticity measures how much a token's price movement amplifies relative to BTC:
 * - Elasticity = (Token % Change) / (BTC % Change)
 * - Elasticity > 1: Token moves more than BTC (amplified)
 * - Elasticity < 1: Token moves less than BTC (dampened)
 * - Elasticity = 1: Token moves exactly with BTC
 *
 * Two separate metrics are calculated per timeframe:
 * - elasticity_long: Average elasticity during BTC upward movements
 * - elasticity_short: Average elasticity during BTC downward movements
 *
 * Results are stored as JSON arrays indexed by timeframe in exchange_symbols columns.
 */
final class CalculateBtcElasticityJob extends BaseQueueableJob
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
        $config = config('kraite.elasticity');

        // Skip if elasticity is disabled
        if (! $config['enabled']) {
            return ['skipped' => true, 'reason' => 'Elasticity calculation disabled in config'];
        }

        $exchangeSymbol = ExchangeSymbol::with('apiSystem')->findOrFail($this->exchangeSymbolId);

        // Find BTC symbol dynamically
        $btcSymbol = Symbol::where('token', $config['btc_token'])->first();

        if (! $btcSymbol) {
            return ['skipped' => true, 'reason' => "BTC symbol not found (token={$config['btc_token']})"];
        }

        // Skip if this IS BTC (can't calculate elasticity with itself)
        if ($exchangeSymbol->symbol_id === $btcSymbol->id) {
            return ['skipped' => true, 'reason' => 'Cannot calculate BTC elasticity with itself'];
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

        // Calculate elasticity for each timeframe
        $elasticityLongResults = [];
        $elasticityShortResults = [];
        $timeframeDetails = [];

        foreach ($timeframes as $timeframe) {
            $result = $this->calculateElasticityForTimeframe(
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
            $elasticityLongResults[$timeframe] = $result['elasticity_long'];
            $elasticityShortResults[$timeframe] = $result['elasticity_short'];
            $timeframeDetails[$timeframe] = [
                'movements_analyzed_long' => $result['movements_analyzed_long'],
                'movements_analyzed_short' => $result['movements_analyzed_short'],
                'elasticity_long' => round($result['elasticity_long'], precision: 4),
                'elasticity_short' => round($result['elasticity_short'], precision: 4),
            ];
        }

        // Only save if we calculated at least one timeframe
        if (! empty($elasticityLongResults)) {
            $exchangeSymbol->btc_elasticity_long = $elasticityLongResults;
            $exchangeSymbol->btc_elasticity_short = $elasticityShortResults;
            $exchangeSymbol->save();
        }

        return [
            'exchange_symbol_id' => $exchangeSymbol->id,
            'symbol' => $exchangeSymbol->token,
            'timeframes_calculated' => count($elasticityLongResults),
            'timeframes' => $timeframeDetails,
        ];
    }

    /**
     * Calculate elasticity for a single timeframe
     */
    public function calculateElasticityForTimeframe(
        ExchangeSymbol $exchangeSymbol,
        ExchangeSymbol $btcExchangeSymbol,
        string $timeframe,
        array $config
    ): array {
        // Fetch candles for this token
        $tokenCandles = Candle::query()
            ->where('exchange_symbol_id', $exchangeSymbol->id)
            ->where('timeframe', $timeframe)
            ->orderBy('timestamp', 'asc') // Important: chronological order
            ->limit($config['window_size'])
            ->get();

        // Fetch candles for BTC
        $btcCandles = Candle::query()
            ->where('exchange_symbol_id', $btcExchangeSymbol->id)
            ->where('timeframe', $timeframe)
            ->orderBy('timestamp', 'asc') // Important: chronological order
            ->limit($config['window_size'])
            ->get();

        // Align timestamps (only use overlapping candles)
        $tokenCandlesByTimestamp = $tokenCandles->keyBy('timestamp');
        $btcCandlesByTimestamp = $btcCandles->keyBy('timestamp');
        $commonTimestamps = array_intersect(
            $tokenCandlesByTimestamp->keys()->all(),
            $btcCandlesByTimestamp->keys()->all()
        );

        if (count($commonTimestamps) < 2) {
            return [
                'error' => 'Need at least 2 aligned candles for elasticity',
                'token_candles' => $tokenCandles->count(),
                'btc_candles' => $btcCandles->count(),
                'aligned_candles' => count($commonTimestamps),
            ];
        }

        // Sort timestamps to ensure chronological order
        sort($commonTimestamps);

        // Calculate percentage changes for consecutive candles
        $longElasticities = [];
        $shortElasticities = [];

        for ($i = 1; $i < count($commonTimestamps); $i++) {
            $prevTimestamp = $commonTimestamps[$i - 1];
            $currTimestamp = $commonTimestamps[$i];

            $tokenPrevClose = (float) $tokenCandlesByTimestamp[$prevTimestamp]->close;
            $tokenCurrClose = (float) $tokenCandlesByTimestamp[$currTimestamp]->close;

            $btcPrevClose = (float) $btcCandlesByTimestamp[$prevTimestamp]->close;
            $btcCurrClose = (float) $btcCandlesByTimestamp[$currTimestamp]->close;

            // Calculate percentage changes
            $tokenPctChange = ($tokenCurrClose - $tokenPrevClose) / $tokenPrevClose;
            $btcPctChange = ($btcCurrClose - $btcPrevClose) / $btcPrevClose;

            // Skip if BTC movement is below threshold (noise filter)
            if (abs($btcPctChange) < $config['min_movement_threshold']) {
                continue;
            }

            // Skip if BTC didn't move (avoid division by zero)
            if ($btcPctChange === 0) {
                continue;
            }

            // Calculate elasticity
            $elasticity = $tokenPctChange / $btcPctChange;

            // Separate into longs (BTC went up) and shorts (BTC went down)
            if ($btcPctChange > 0) {
                $longElasticities[] = $elasticity;
            } else {
                $shortElasticities[] = $elasticity;
            }
        }

        // Calculate average elasticity for longs and shorts
        $avgElasticityLong = ! empty($longElasticities)
            ? array_sum($longElasticities) / count($longElasticities)
            : 0.0;

        $avgElasticityShort = ! empty($shortElasticities)
            ? array_sum($shortElasticities) / count($shortElasticities)
            : 0.0;

        return [
            'elasticity_long' => $avgElasticityLong,
            'elasticity_short' => $avgElasticityShort,
            'movements_analyzed_long' => count($longElasticities),
            'movements_analyzed_short' => count($shortElasticities),
        ];
    }
}
