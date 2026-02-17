<?php

declare(strict_types=1);

namespace Kraite\Core\Indicators\RefreshData;

use Kraite\Core\Abstracts\BaseIndicator;
use Kraite\Core\Contracts\Indicators\DirectionIndicator;

final class EMAsConvergence extends BaseIndicator implements DirectionIndicator
{
    public string $endpoint = 'emas-convergence';

    public function conclusion(): ?string
    {
        return $this->direction();
    }

    /**
     * Picks all the EMAs that exist on the total indicators array, and
     * verifies if they follow the same trend, by the same order (ema with
     * higher period, should have a lower value than ema with lower period),
     * for longs, and vice-versa for shorts.
     */
    public function direction(): ?string
    {
        // Collect only the EMA indicators from the data
        $emas = collect($this->data)
            ->filter(static fn ($indicator, $key) => str_starts_with(haystack: $key, needle: 'ema-'));

        if ($emas->count() < 2) {
            // Not enough EMAs for analysis
            return null;
        }

        // Sort EMAs by their period (e.g., ema-18 < ema-10)
        $sortedEmas = $emas->sortKeysUsing(
            static fn ($keyA, $keyB) => (int) str_replace(search: 'ema-', replace: '', subject: $keyA) <=> (int) str_replace(search: 'ema-', replace: '', subject: $keyB)
        );

        $previousValue = null;
        $trend = null;

        foreach ($sortedEmas as $key => $ema) {
            $values = $ema['result']['value'];

            if (! isset($values[1]) || ! isset($values[0])) {
                // Invalid or missing EMA values, skip this indicator
                return null;
            }

            // Determine the current trend for this EMA
            $currentTrend = $values[1] >= $values[0] ? 'LONG' : 'SHORT';

            if (is_null($trend)) {
                $trend = $currentTrend; // Initialize the trend
            } elseif ($trend !== $currentTrend) {
                // If trends are inconsistent, no convergence
                return null;
            }

            // Validate EMA order (higher periods should have lower values for LONG, higher for SHORT)
            if ($previousValue !== null) {
                if ($trend === 'LONG' && $values[1] > $previousValue) {
                    return null; // LONG convergence broken
                }
                if ($trend === 'SHORT' && $values[1] < $previousValue) {
                    return null; // SHORT convergence broken
                }
            }

            $previousValue = $values[1]; // Update the previous EMA value
        }

        return $trend; // Return the determined trend (LONG or SHORT)
    }
}
