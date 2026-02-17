<?php

declare(strict_types=1);

namespace Kraite\Core\Trading\Concerns;

use InvalidArgumentException;
use Kraite\Core\Trading\Engine;
use Kraite\Core\Support\Math;

trait HasComputationHelpers
{
    /**
     * Step-ratio accessor that clamps the index to the last available value.
     * Accepts negative indices by clamping to 0.
     *
     * @param  array<int,mixed>  $values
     */
    public static function returnLadderedValue(array $values, int $index): mixed
    {
        if (empty($values)) {
            throw new InvalidArgumentException('Multipliers array must not be empty.');
        }

        $i = $index < 0 ? 0 : $index;

        return $values[min($i, count($values) - 1)];
    }

    /**
     * Converts a percentage value (e.g. "0.36" for 0.36%) into a decimal string with SCALE precision.
     * Enforces numeric input and p >= 0.
     */
    public static function pctToDecimal(string $pct, string $label): string
    {
        if (! is_numeric($pct)) {
            throw new InvalidArgumentException("{$label} must be numeric.");
        }

        $p = Math::div($pct, '100', Engine::SCALE);

        if (Math::lt($p, '0', Engine::SCALE)) {
            throw new InvalidArgumentException("{$label} must be >= 0.");
        }

        return $p;
    }

    /**
     * Returns whether we are in testing mode (e.g., use binance testnet).
     */
    public static function testingMode(): bool
    {
        return (bool) config('kraite.testing.enabled');
    }
}
