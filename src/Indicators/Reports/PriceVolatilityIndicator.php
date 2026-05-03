<?php

declare(strict_types=1);

namespace Kraite\Core\Indicators\Reports;

use Kraite\Core\Abstracts\BaseIndicator;
use Kraite\Core\Support\Math;

/**
 * PriceVolatilityIndicator
 *
 * Uses TAAPI "candle" endpoint. Works with:
 *  - a single candle object (no `results`)
 *  - an array of candle objects when `results = X` (e.g., 2000)
 *
 * Per candle:
 *   volatility% = ((high - low) / close) * 100
 *
 * Returns:
 *  - overall price volatility percentage average (across all samples)
 *  - last N ticks price volatility average (N = last_window param or 30 by default)
 */
final class PriceVolatilityIndicator extends BaseIndicator
{
    /** @var string TAAPI endpoint */
    public string $endpoint = 'candle';

    /**
     * Build conclusion payload:
     * - overall_average: float|null
     * - lastN_average: float|null
     * - last_window: int
     * - samples: int
     */
    public function conclusion(): array
    {
        // Raw can be a single candle, a list of candles, or a mapped array
        $raw = $this->data;

        // Normalize to an array of candles
        $candles = $this->normalizeCandles($raw);

        if (empty($candles)) {
            return [
                'overall_average' => null,
                'lastN_average' => null,
                'last_window' => (int) ($this->parameters['last_window'] ?? 30),
                'samples' => 0,
            ];
        }

        // Compute per-candle volatility %
        $vols = [];
        foreach ($candles as $c) {
            $v = $this->volatilityPercent($c);
            if ($v !== null) {
                $vols[] = $v;
            }
        }

        $samples = count($vols);
        if ($samples === 0) {
            return [
                'overall_average' => null,
                'lastN_average' => null,
                'last_window' => (int) ($this->parameters['last_window'] ?? 30),
                'samples' => 0,
            ];
        }

        // Overall average across all available samples
        $overall = array_sum($vols) / $samples;

        // Last N ticks average (N configurable via parameters)
        $window = max(1, (int) ($this->parameters['last_window'] ?? 30));
        $last = array_slice($vols, -$window);
        $lastAvg = array_sum($last) / count($last);

        // Persist a compact summary under data['volatility'] for history/debug
        $this->data['volatility'] = [
            'overall_average' => $overall,
            'lastN_average' => $lastAvg,
            'last_window' => $window,
            'samples' => $samples,
        ];

        return $this->data['volatility'];
    }

    /**
     * Normalize the TAAPI response into an array of candle arrays.
     *
     * Expected shapes:
     *  - Single candle:
     *      ['timestamp' => int, 'open' => float, 'high' => float, 'low' => float, 'close' => float, 'volume' => float]
     *  - Multiple candles (results=X):
     *      [
     *          ['timestamp' => ..., 'open' => ..., 'high' => ..., 'low' => ..., 'close' => ..., 'volume' => ...],
     *          ...
     *      ]
     *
     * @param  mixed  $raw
     * @return array<int,array<string,mixed>>
     */
    public function normalizeCandles($raw): array
    {
        // If it's already a list of candles (0-indexed), return as-is.
        if (is_array($raw) && array_key_exists(key: 0, array: $raw) && is_array($raw[0])) {
            return $raw;
        }

        // If it's a single candle (associative), wrap it into a list.
        if (is_array($raw) && isset($raw['high'], $raw['low'], $raw['close'])) {
            return [$raw];
        }

        // If a mapper wrapped values differently, detect common containers.
        if (is_array($raw) && isset($raw['candles']) && is_array($raw['candles'])) {
            return $raw['candles'];
        }

        return [];
    }

    /**
     * Calculate volatility percentage for a single candle.
     *
     * volatility% = ((high - low) / close) * 100
     *
     * @param  array<string,mixed>  $candle
     */
    public function volatilityPercent(array $candle): ?float
    {
        if (! isset($candle['high'], $candle['low'], $candle['close']) ||
            ! is_numeric($candle['high']) ||
            ! is_numeric($candle['low']) ||
            ! is_numeric($candle['close'])
        ) {
            return null;
        }

        $close = (string) $candle['close'];
        if (Math::lte($close, '0')) {
            return null; // avoid division by zero or negative close
        }

        $high = (string) $candle['high'];
        $low = (string) $candle['low'];

        return (float) Math::mul(Math::div(Math::sub($high, $low), $close, 12), '100');
    }
}
