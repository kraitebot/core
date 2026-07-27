<?php

declare(strict_types=1);

namespace Kraite\Core\Enums;

enum OrderStatus: string
{
    case New = 'NEW';
    case PartiallyFilled = 'PARTIALLY_FILLED';
    case Filled = 'FILLED';
    case Triggered = 'TRIGGERED';
    case Cancelled = 'CANCELLED';
    case Expired = 'EXPIRED';
    case Rejected = 'REJECTED';

    /**
     * @return list<string>
     */
    public static function workingValues(): array
    {
        return [self::New->value, self::PartiallyFilled->value];
    }

    /**
     * @return list<string>
     */
    public static function workingOrFilledValues(): array
    {
        return [...self::workingValues(), self::Filled->value];
    }

    /**
     * @return list<string>
     */
    public static function terminalWithoutFillValues(): array
    {
        return [self::Cancelled->value, self::Expired->value, self::Rejected->value];
    }

    /**
     * Lifecycle rank for monotonicity guards. Burst fills (large MARKET
     * orders splitting into many partial executions within the same
     * second) can deliver user-data frames out of order — a NEW or
     * PARTIALLY_FILLED frame processed after the FILLED frame is stale,
     * not a real transition. Comparing ranks lets appliers reject any
     * frame that would move an order's lifecycle backwards. All terminal
     * states share one rank so legitimate terminal-to-terminal rewrites
     * keep applying exactly as before.
     */
    public static function rank(string $status): int
    {
        return match ($status) {
            self::New->value => 0,
            self::PartiallyFilled->value => 1,
            default => 2,
        };
    }

    public function isWorkingOnExchange(): bool
    {
        return $this === self::New || $this === self::PartiallyFilled;
    }

    public function closesPosition(): bool
    {
        return $this === self::Filled || $this === self::Triggered;
    }

    public function requiresReplacement(): bool
    {
        return in_array($this, [self::Cancelled, self::Expired, self::Rejected], true);
    }
}
