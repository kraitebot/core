<?php

declare(strict_types=1);

namespace Kraite\Core\Trading;

use Kraite\Core\Models\Account;
use Kraite\Core\Support\Math;
use Kraite\Core\Trading\Concerns\HasComputationHelpers;
use Kraite\Core\Trading\Concerns\HasMinNotionalChecks;
use Kraite\Core\Trading\Concerns\HasOrderCalculations;
use Kraite\Core\Trading\Concerns\HasPnLCalculations;
use Kraite\Core\Trading\Concerns\HasTradingGuards;

/**
 * Kraite Engine — Unbounded ladder model (production)
 *
 * Description:
 * - MARKET leg uses the entire (marketMargin × leverage) as quote notional (no divider).
 * - LIMIT ladder is unbounded: quantities are chained from the MARKET quantity using step ratios.
 * - Feasible leverage selection uses a conservative unit-leverage worst-case K with configurable headroom.
 * - Rungs that round to zero quantity after formatting are dropped.
 * - Rung prices are clamped to symbol min/max and warnings are recorded.
 */
final class Kraite
{
    use HasComputationHelpers;
    use HasMinNotionalChecks;
    use HasOrderCalculations;
    use HasPnLCalculations;
    use HasTradingGuards;

    /**
     * Global decimal scale used across money/size math.
     * Keep aligned with Math::DEFAULT_SCALE.
     */
    public const SCALE = 16;

    public function __construct(
        public Account $account,
    ) {}

    /**
     * Create a new instance with the given account.
     */
    public static function withAccount(Account $account): self
    {
        return new self($account);
    }

    /**
     * floor(a / b) for positive decimals (returns int).
     */
    public static function floorPosDiv(string $a, string $b): int
    {
        // bcdiv with scale 0 truncates towards zero; with positive inputs it's floor.
        $q = (int) Math::div($a, $b, 0);

        return max(0, $q);
    }

    /**
     * ceil(a / b) for positive decimals (returns int).
     */
    public static function ceilPosDiv(string $a, string $b): int
    {
        $q = (int) Math::div($a, $b, 0); // floor
        $prod = Math::mul((string) $q, $b, self::SCALE);

        if (Math::lt($prod, $a, self::SCALE)) {
            return $q + 1;
        }

        return $q;
    }
}
