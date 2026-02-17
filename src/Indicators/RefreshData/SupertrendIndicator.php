<?php

declare(strict_types=1);

namespace Kraite\Core\Indicators\RefreshData;

use Kraite\Core\Abstracts\BaseIndicator;
use Kraite\Core\Contracts\Indicators\DirectionIndicator;

/**
 * Supertrend Indicator
 *
 * Uses ATR (Average True Range) to determine trend direction.
 * Cross-exchange proof: uses only price data (OHLC), no volume.
 *
 * TAAPI Response: {"value": 37459.26, "valueAdvice": "long"}
 * - value: The supertrend line value
 * - valueAdvice: "long" or "short" indicating trend direction
 */
final class SupertrendIndicator extends BaseIndicator implements DirectionIndicator
{
    public string $endpoint = 'supertrend';

    public function conclusion(): ?string
    {
        return $this->direction();
    }

    public function direction(): ?string
    {
        $advice = $this->data['valueAdvice'] ?? null;

        if ($advice === null) {
            return null;
        }

        // Handle array response (when results > 1, TAAPI returns arrays)
        // Use the most recent value (last element)
        if (is_array($advice)) {
            $advice = end($advice);
        }

        if (! is_string($advice)) {
            return null;
        }

        // TAAPI returns 'long' or 'short' in lowercase
        return match (strtolower($advice)) {
            'long' => 'LONG',
            'short' => 'SHORT',
            default => null,
        };
    }
}
