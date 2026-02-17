<?php

declare(strict_types=1);

namespace Kraite\Core\Contracts\Indicators;

/**
 * Interface for indicators that validate market conditions.
 *
 * Validation indicators determine whether the market meets
 * certain criteria required for trading (e.g., sufficient trend strength).
 */
interface ValidationIndicator
{
    /**
     * Validates whether market conditions meet the indicator's criteria.
     *
     * @return bool true if conditions are valid, false otherwise
     */
    public function isValid(): bool;
}
