<?php

declare(strict_types=1);

namespace Kraite\Core\Indicators\RefreshData;

use Kraite\Core\Abstracts\BaseIndicator;
use Kraite\Core\Contracts\Indicators\ValidationIndicator;

/**
 * ATR (Average True Range) Indicator
 *
 * Measures market volatility. Not directional — used by Risk agent
 * for position sizing and volatility regime classification.
 *
 * TAAPI Response (with results=1):
 * {"value": [X.XX]}
 *
 * Conclusion: raw ATR value as string (consumed by Risk agent for sizing).
 * No LONG/SHORT — this is a volatility metric, not a direction signal.
 */
final class ATRIndicator extends BaseIndicator implements ValidationIndicator
{
    public string $endpoint = 'atr';

    public function conclusion(): bool
    {
        return $this->isValid();
    }

    /**
     * ATR is "valid" when it returns a non-zero value.
     * The actual value is stored in indicator_histories data JSON
     * for the Risk agent to consume.
     */
    public function isValid(): bool
    {
        $values = $this->data['value'] ?? null;

        if (! is_array($values) || empty($values)) {
            return false;
        }

        return $values[0] > 0;
    }
}
